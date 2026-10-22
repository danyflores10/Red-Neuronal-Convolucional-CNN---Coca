"""Red neuronal convolucional (YOLO26-cls / YOLO11-cls) que clasifica las fotos.

- Carga el modelo entrenado en Colab (`weights/best.pt`).
- TTA (test-time augmentation): cada foto se evalúa también volteada y rotada
  180° (transformaciones que el modelo vio en el entrenamiento) y se promedian
  las probabilidades. Da resultados más estables ante el ángulo de la foto.
- Detector "fuera de distribución" (kNN sobre embeddings de la propia red): mide
  qué tan parecida es cada foto a las imágenes de entrenamiento, para no forzar
  una clase sobre algo que no se parece a nada del dataset.
- Mapas de atención (Grad-CAM): muestran en qué zonas de la hoja se fijó la red
  para decidir, usando los gradientes de su última capa convolucional.

El preprocesamiento es exactamente el de validación de Ultralytics
(redimensionar el lado corto a `imgsz` + recorte central), así la API ve las
fotos igual que el modelo durante el entrenamiento.
"""

from __future__ import annotations

import base64
import copy
import io
import logging
import os
import threading
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from PIL import Image

from .analisis import PrediccionImagen

# Un servidor no debe instalar paquetes con pip ni enviar telemetría mientras atiende peticiones
os.environ.setdefault("YOLO_AUTOINSTALL", "false")
os.environ.setdefault("YOLO_OFFLINE", "true")

log = logging.getLogger("api_coca")

VISTAS_TTA = 4
LADO_MAPA = 224  # tamaño de las imágenes de los mapas de atención que se envían a la interfaz


def generar_vistas_tta(imagen: Image.Image) -> list[Image.Image]:
    """Original, espejo horizontal, espejo vertical y rotación de 180°."""
    return [
        imagen,
        imagen.transpose(Image.Transpose.FLIP_LEFT_RIGHT),
        imagen.transpose(Image.Transpose.FLIP_TOP_BOTTOM),
        imagen.transpose(Image.Transpose.ROTATE_180),
    ]


def superponer_mapa(imagen: Image.Image, cam: np.ndarray, lado: int = LADO_MAPA) -> str:
    """Mapa de calor (rojo = más atención) sobre la foto, como data URI JPEG."""
    from matplotlib import colormaps

    calor = colormaps["jet"](cam)[..., :3] * 255.0
    base = np.asarray(imagen.convert("RGB"), dtype=np.float32)
    peso = 0.25 + 0.45 * cam[..., None]  # las zonas sin atención dejan ver más la foto
    mezcla = Image.fromarray(np.clip(base * (1 - peso) + calor * peso, 0, 255).astype(np.uint8))
    mezcla.thumbnail((lado, lado), Image.Resampling.LANCZOS)
    buffer = io.BytesIO()
    mezcla.save(buffer, format="JPEG", quality=85)
    return "data:image/jpeg;base64," + base64.b64encode(buffer.getvalue()).decode("ascii")


@dataclass
class DetectorOOD:
    """Distancia coseno al k-ésimo vecino más cercano del set de entrenamiento."""

    banco: np.ndarray  # [N, D] embeddings de entrenamiento normalizados (L2)
    k: int
    umbral: float

    @classmethod
    def cargar(cls, ruta: Path) -> "DetectorOOD":
        with np.load(ruta) as datos:
            banco = datos["embeddings"].astype(np.float32)
            k = int(datos["k"])
            umbral = float(datos["umbral"])
        banco /= np.clip(np.linalg.norm(banco, axis=1, keepdims=True), 1e-12, None)
        return cls(banco=banco, k=max(1, min(k, banco.shape[0])), umbral=umbral)

    @property
    def dimension(self) -> int:
        return int(self.banco.shape[1])

    def distancias(self, embeddings: np.ndarray) -> np.ndarray:
        e = embeddings.astype(np.float32)
        e /= np.clip(np.linalg.norm(e, axis=1, keepdims=True), 1e-12, None)
        similitudes = e @ self.banco.T
        kesima = -np.partition(-similitudes, self.k - 1, axis=1)[:, self.k - 1]
        return 1.0 - kesima

    def similitud(self, distancia: float) -> float:
        """0-1 para la interfaz: 1 = idéntica al dataset, 0.5 = en el límite aceptado."""
        return float(np.clip(1.0 - 0.5 * distancia / max(self.umbral, 1e-6), 0.0, 1.0))


class ClasificadorCoca:
    def __init__(
        self,
        ruta_modelo: Path,
        ruta_ood: Path | None = None,
        device: str = "cpu",
        imgsz: int = 0,
        tta: bool | None = None,
        mapas_atencion: bool = True,
    ):
        self.ruta_modelo = Path(ruta_modelo)
        self.ruta_ood = Path(ruta_ood) if ruta_ood else None
        self._device_pedido = device
        self._imgsz_pedido = imgsz
        self._tta_pedido = tta
        self._mapas_pedidos = mapas_atencion

        self.listo = False
        self.error: str | None = "El modelo aún no se cargó."
        self.nombres: dict[int, str] = {}
        self.imgsz = 0
        self.tta = True
        self.device = "cpu"
        self.ood: DetectorOOD | None = None
        self.arquitectura = ""
        self._modelo = None
        self._red_gradcam = None
        self._recorte = None
        self._lock = threading.Lock()

    @property
    def mapas_activos(self) -> bool:
        return self._red_gradcam is not None

    # ------------------------------------------------------------------ carga
    def cargar(self, meta: dict) -> None:
        if not self.ruta_modelo.exists():
            self.error = (
                f"No se encontró el modelo entrenado en '{self.ruta_modelo}'. Entrena la red con el notebook "
                "de Colab y copia best.pt, model_meta.json y ood_embeddings.npz en la carpeta weights/."
            )
            log.error(self.error)
            return
        try:
            import torch
            import torchvision.transforms as T
            from ultralytics import YOLO

            modelo = YOLO(str(self.ruta_modelo))
            if modelo.task != "classify":
                raise ValueError(
                    f"el archivo es un modelo de tipo '{modelo.task}'; esta API necesita uno de "
                    "clasificación (yolo26*-cls o yolo11*-cls)."
                )

            self.nombres = {int(i): str(n) for i, n in modelo.names.items()}
            # El tamaño guardado en el propio modelo manda sobre el de model_meta.json
            self.imgsz = int(self._imgsz_pedido or modelo.overrides.get("imgsz") or meta.get("imgsz") or 224)
            self.tta = bool(meta.get("tta_recomendado", True)) if self._tta_pedido is None else self._tta_pedido
            pedido = str(self._device_pedido).strip().lower()
            self.device = ("0" if torch.cuda.is_available() else "cpu") if pedido == "auto" else pedido
            self.arquitectura = str(meta.get("arquitectura") or self.ruta_modelo.stem)

            self._recorte = T.Compose(
                [T.Resize(self.imgsz, interpolation=T.InterpolationMode.BILINEAR), T.CenterCrop(self.imgsz)]
            )
            if self._mapas_pedidos:
                # Copia propia para Grad-CAM, hecha antes de la primera inferencia (pesos normales, no de inferencia)
                dispositivo = "cpu" if self.device == "cpu" else "cuda:" + self.device.replace("cuda:", "")
                self._red_gradcam = copy.deepcopy(modelo.model).float().eval().to(dispositivo)
                for parametro in self._red_gradcam.parameters():
                    parametro.requires_grad_(False)
            self._modelo = modelo

            # Primera inferencia: prepara el predictor (y mide la dimensión de los embeddings)
            prueba = [Image.new("RGB", (self.imgsz, self.imgsz), (110, 140, 80))]
            self._probabilidades(prueba)
            if self.ruta_ood and self.ruta_ood.exists():
                self.ood = self._cargar_ood(self._embeddings(prueba).shape[1])
            else:
                self.ood = None
                if self.ruta_ood:
                    log.warning("Sin %s: el detector de fotos ajenas al dataset queda desactivado.", self.ruta_ood.name)

            self.listo = True
            self.error = None
            log.info(
                "Modelo cargado: %s | %d clases | imgsz=%d | TTA=%s | device=%s | detector OOD=%s | mapas de atención=%s",
                self.ruta_modelo.name,
                len(self.nombres),
                self.imgsz,
                "sí" if self.tta else "no",
                self.device,
                "activo" if self.ood else "inactivo",
                "sí" if self.mapas_activos else "no",
            )
        except Exception as exc:  # cualquier fallo deja la API en pie y explica el motivo
            self.listo = False
            self.error = f"No se pudo cargar el modelo '{self.ruta_modelo.name}': {exc}"
            log.exception(self.error)

    def _cargar_ood(self, dimension: int) -> DetectorOOD | None:
        try:
            detector = DetectorOOD.cargar(self.ruta_ood)
        except Exception as exc:
            log.error("No se pudo leer %s (%s). Detector OOD desactivado.", self.ruta_ood, exc)
            return None
        if detector.dimension != dimension:
            log.error(
                "%s no corresponde a este modelo (dimensión %d, se esperaba %d). Detector OOD desactivado.",
                self.ruta_ood.name,
                detector.dimension,
                dimension,
            )
            return None
        return detector

    # ------------------------------------------------------------- inferencia
    def _probabilidades(self, vistas: list[Image.Image]) -> np.ndarray:
        """Probabilidades [n, clases]; todas las vistas van en un solo lote."""
        resultados = self._modelo.predict(vistas, imgsz=self.imgsz, device=self.device, verbose=False)
        return np.stack([r.probs.data.float().cpu().numpy() for r in resultados]).astype(np.float64)

    def _embeddings(self, imagenes: list[Image.Image]) -> np.ndarray:
        """Embeddings [n, D] de la penúltima capa (API oficial YOLO.embed, igual que en el notebook)."""
        import torch

        vectores = self._modelo.embed(imagenes, imgsz=self.imgsz, device=self.device, verbose=False)
        return torch.stack([v.float().flatten() for v in vectores]).cpu().numpy()

    def predecir(self, imagenes: list[Image.Image]) -> list[PrediccionImagen]:
        if not self.listo:
            raise RuntimeError(self.error or "El modelo no está disponible.")

        recortes = [self._recorte(im) for im in imagenes]
        por_imagen = VISTAS_TTA if self.tta else 1
        vistas = [v for r in recortes for v in (generar_vistas_tta(r) if self.tta else [r])]

        with self._lock:  # la red no es segura entre hilos: una inferencia a la vez
            probs = self._probabilidades(vistas)
            embeddings = self._embeddings(recortes) if self.ood else None

        probs = probs.reshape(len(imagenes), por_imagen, -1).mean(axis=1)
        distancias = self.ood.distancias(embeddings) if self.ood else [None] * len(imagenes)

        return [
            PrediccionImagen(
                probs=p / p.sum(),
                distancia_ood=None if d is None else float(d),
                fuera_de_distribucion=bool(self.ood is not None and d is not None and d > self.ood.umbral),
            )
            for p, d in zip(probs, distancias)
        ]

    # ------------------------------------------------------- mapas de atención
    def _gradcam(self, recortes: list[Image.Image], indice: int) -> np.ndarray:
        """Grad-CAM de la clase `indice` sobre la última capa convolucional: [n, S, S] con valores 0-1."""
        import torch
        import torch.nn.functional as F
        import torchvision.transforms.functional as TF

        red = self._red_gradcam
        dispositivo = next(red.parameters()).device
        x = torch.stack([TF.to_tensor(r) for r in recortes]).to(dispositivo).requires_grad_(True)
        activaciones = {}
        gancho = red.model[-2].register_forward_hook(lambda _m, _e, salida: activaciones.__setitem__("a", salida))
        try:
            with torch.enable_grad():
                salida = red(x)
                logits = salida[1] if isinstance(salida, (tuple, list)) else salida
                gradientes = torch.autograd.grad(logits[:, indice].sum(), activaciones["a"])[0]
        finally:
            gancho.remove()

        pesos = gradientes.mean(dim=(2, 3), keepdim=True)
        cam = torch.relu((pesos * activaciones["a"]).sum(dim=1, keepdim=True))
        cam = F.interpolate(cam, size=x.shape[-2:], mode="bilinear", align_corners=False)[:, 0]
        cam = cam - cam.amin(dim=(1, 2), keepdim=True)
        cam = cam / cam.amax(dim=(1, 2), keepdim=True).clamp(min=1e-8)
        return cam.detach().cpu().numpy()

    def mapas_atencion(self, imagenes: list[Image.Image], indice: int) -> list[str | None]:
        """Un mapa por foto (data URI JPEG). Si algo falla, el análisis sigue sin mapas."""
        if not self.listo or self._red_gradcam is None:
            return [None] * len(imagenes)
        try:
            recortes = [self._recorte(im) for im in imagenes]
            with self._lock:
                cams = self._gradcam(recortes, indice)
            return [superponer_mapa(r, c) for r, c in zip(recortes, cams)]
        except Exception:
            log.exception("No se pudieron generar los mapas de atención")
            return [None] * len(imagenes)
