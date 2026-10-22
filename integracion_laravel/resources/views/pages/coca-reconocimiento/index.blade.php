@extends('layouts.master')

@section('styles')
<link rel="stylesheet" href="{{ asset('build/assets/libs/sweetalert2/sweetalert2.min.css') }}">
<style>
    /* Paleta del módulo atada al tema del sistema (color primario configurable).
       Si cambias el color del sistema, todo el módulo se adapta solo. */
    .coca-reco {
        --rp: var(--primary-rgb);                                  /* rgb del primario  */
        --ra: var(--warning-rgb);                                  /* rgb del acento (dorado del tema) */
        --rec-primary: rgb(var(--primary-rgb));
        --rec-dark:    color-mix(in srgb, rgb(var(--primary-rgb)) 72%, #000);
        --rec-darker:  color-mix(in srgb, rgb(var(--primary-rgb)) 55%, #000);
        --rec-light:   color-mix(in srgb, rgb(var(--primary-rgb)) 60%, #fff);
        --rec-accent:  rgb(var(--warning-rgb));
        --rec-accent-soft: color-mix(in srgb, rgb(var(--warning-rgb)) 70%, #fff);
    }

    /* ==================== Escenario de cámara ==================== */
    .cam-card {
        border: none;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 10px 34px rgba(var(--rp), 0.10);
    }

    .cam-stage {
        position: relative;
        background:
            radial-gradient(circle at 28% 22%, rgba(var(--rp), 0.10), transparent 45%),
            radial-gradient(circle at 78% 75%, rgba(var(--ra), 0.08), transparent 42%),
            #0b1410;
        border-radius: 18px;
        overflow: hidden;
        aspect-ratio: 4 / 3;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.06), 0 18px 40px rgba(11, 20, 16, 0.35);
    }

    .cam-stage video,
    .cam-stage canvas.cam-photo {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .cam-stage video.mirrored { transform: scaleX(-1); }

    /* Marco de escaneo con esquinas */
    .scan-frame {
        position: absolute;
        inset: 9%;
        pointer-events: none;
        z-index: 3;
    }

    .scan-frame span {
        position: absolute;
        width: 36px;
        height: 36px;
        border: 3px solid var(--rec-accent);
        border-radius: 6px;
        opacity: 0.95;
        filter: drop-shadow(0 0 7px rgba(var(--ra), 0.6));
        transition: all 0.3s ease;
    }

    .scan-frame span:nth-child(1) { top: 0; left: 0; border-right: none; border-bottom: none; }
    .scan-frame span:nth-child(2) { top: 0; right: 0; border-left: none; border-bottom: none; }
    .scan-frame span:nth-child(3) { bottom: 0; left: 0; border-right: none; border-top: none; }
    .scan-frame span:nth-child(4) { bottom: 0; right: 0; border-left: none; border-top: none; }

    .cam-stage.bursting .scan-frame span { border-color: var(--rec-light); filter: drop-shadow(0 0 9px rgba(var(--rp), 0.8)); }

    .cam-stage.analyzing::after {
        content: '';
        position: absolute;
        inset: 0;
        background: rgba(var(--rp), 0.18);
        z-index: 2;
    }

    /* Mensajes dentro del escenario */
    .cam-idle {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 14px;
        color: #cfe5d8;
        text-align: center;
        padding: 1.5rem;
        z-index: 5;
    }

    .cam-idle .cam-idle-icon {
        width: 90px;
        height: 90px;
        border-radius: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(var(--rp), 0.13);
        border: 1px solid rgba(var(--rp), 0.35);
        animation: idleFloat 3s ease-in-out infinite;
    }

    @keyframes idleFloat {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-8px); }
    }

    /* Chip EN VIVO */
    .cam-live-chip {
        position: absolute;
        top: 14px;
        left: 14px;
        z-index: 6;
        display: none;
        align-items: center;
        gap: 7px;
        background: rgba(11, 20, 16, 0.62);
        backdrop-filter: blur(6px);
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        padding: 5px 12px;
        border-radius: 30em;
        border: 1px solid rgba(255, 255, 255, 0.18);
    }

    .cam-stage.live .cam-live-chip { display: inline-flex; }

    .cam-live-chip .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--rec-light);
        animation: liveBlink 1.4s ease-in-out infinite;
    }

    @keyframes liveBlink {
        0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(var(--rp), 0.7); }
        50%      { opacity: 0.55; box-shadow: 0 0 0 6px rgba(var(--rp), 0); }
    }

    /* Contador de fotos capturadas */
    .burst-chip {
        position: absolute;
        top: 14px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 7;
        display: none;
        align-items: center;
        gap: 8px;
        background: rgba(var(--ra), 0.92);
        color: #1a160b;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        padding: 7px 16px;
        border-radius: 30em;
        box-shadow: 0 6px 18px rgba(var(--ra), 0.45);
    }

    .cam-stage.has-shots .burst-chip { display: inline-flex; animation: burstPop 0.3s ease; }

    @keyframes burstPop {
        from { transform: translateX(-50%) scale(0.7); opacity: 0; }
        to   { transform: translateX(-50%) scale(1); opacity: 1; }
    }

    /* GIF de IA dentro del recuadro de la cámara (mientras analiza).
       Vidrio esmerilado intenso: la foto de fondo no debe reconocerse. */
    .stage-ia {
        position: absolute;
        inset: 0;
        z-index: 9;
        display: none;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 14px;
        overflow: hidden;
        background: linear-gradient(150deg, rgba(var(--rp), 0.40), rgba(11, 20, 16, 0.42) 55%, rgba(var(--rp), 0.38));
        backdrop-filter: blur(34px) saturate(1.4);
        -webkit-backdrop-filter: blur(34px) saturate(1.4);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.22);
        pointer-events: none;
    }

    .cam-stage.analyzing .stage-ia { display: flex; }

    /* Orbes de color flotantes detrás del GIF (decoración glass) */
    .stage-ia::before,
    .stage-ia::after {
        content: '';
        position: absolute;
        border-radius: 50%;
        filter: blur(36px);
        opacity: 0.55;
    }

    .stage-ia::before {
        width: 190px;
        height: 190px;
        background: radial-gradient(circle, rgba(var(--rp), 0.65), transparent 70%);
        top: 8%;
        left: 6%;
        animation: orbFloat 6s ease-in-out infinite;
    }

    .stage-ia::after {
        width: 170px;
        height: 170px;
        background: radial-gradient(circle, rgba(168, 85, 247, 0.55), transparent 70%);
        bottom: 6%;
        right: 8%;
        animation: orbFloat 7s ease-in-out infinite reverse;
    }

    @keyframes orbFloat {
        0%, 100% { transform: translate(0, 0) scale(1); }
        50%      { transform: translate(22px, -18px) scale(1.15); }
    }

    .stage-ia img {
        width: 150px;
        height: 150px;
        object-fit: contain;
        position: relative;
        z-index: 2;
        animation: iaFloat 2.6s ease-in-out infinite;
        filter: drop-shadow(0 10px 30px rgba(99, 102, 241, 0.5));
    }

    @keyframes iaFloat {
        0%, 100% { transform: translateY(0) scale(1); }
        50%      { transform: translateY(-10px) scale(1.06); }
    }

    .stage-ia .stage-ia-text {
        position: relative;
        z-index: 2;
        color: #fff;
        font-weight: 800;
        font-size: 0.95rem;
        letter-spacing: 0.05em;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        background: rgba(255, 255, 255, 0.12);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.28);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.3), 0 8px 22px rgba(0, 0, 0, 0.25);
        padding: 8px 20px;
        border-radius: 30em;
    }

    /* Botón voltear cámara */
    .cam-flip {
        position: absolute;
        top: 14px;
        right: 14px;
        z-index: 6;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        border: 1px solid rgba(255, 255, 255, 0.25);
        background: rgba(11, 20, 16, 0.62);
        backdrop-filter: blur(6px);
        color: #fff;
        display: none;
        align-items: center;
        justify-content: center;
        transition: transform 0.25s ease, background 0.25s ease;
    }

    .cam-stage.live .cam-flip { display: inline-flex; }

    .cam-flip:hover { background: rgba(var(--rp), 0.85); transform: rotate(180deg); }

    /* ==================== Controles tipo app de cámara ==================== */
    .cam-controls {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 22px;
        margin-top: 1.25rem;
        margin-bottom: 30px;
        min-height: 86px;
    }

    .shutter-btn {
        width: 86px;
        height: 86px;
        border-radius: 50%;
        border: none;
        padding: 6px;
        cursor: pointer;
        position: relative;
        background: conic-gradient(from 135deg, var(--rec-accent), var(--rec-accent-soft), var(--rec-accent), var(--rec-accent));
        transition: transform 0.18s ease, box-shadow 0.25s ease;
        box-shadow: 0 12px 30px rgba(var(--rp), 0.32), 0 0 0 1px rgba(var(--ra), 0.4);
    }

    /* Aro interior blanco que separa el borde dorado del núcleo */
    .shutter-btn::before {
        content: '';
        position: absolute;
        inset: 4px;
        border-radius: 50%;
        background: #fff;
        z-index: 1;
    }

    .shutter-btn .shutter-core {
        position: relative;
        z-index: 2;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: radial-gradient(circle at 35% 30%, var(--rec-light), var(--rec-primary) 55%, var(--rec-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.55rem;
        box-shadow: inset 0 2px 6px rgba(255, 255, 255, 0.35), inset 0 -4px 10px rgba(0, 0, 0, 0.25);
        transition: transform 0.18s ease;
    }

    .shutter-btn:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 16px 38px rgba(var(--rp), 0.4), 0 0 0 1px rgba(var(--ra), 0.55);
    }

    .shutter-btn:hover:not(:disabled) .shutter-core { transform: scale(0.93); }
    .shutter-btn:active:not(:disabled) .shutter-core { transform: scale(0.86); }

    .shutter-btn:disabled {
        cursor: not-allowed;
        background: #e3ebe5;
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.10);
    }

    .shutter-btn:disabled .shutter-core {
        background: radial-gradient(circle at 35% 30%, #cfe0d6, #aebdb4);
        box-shadow: none;
    }

    .shutter-btn .shutter-count {
        position: absolute;
        inset: 6px;
        z-index: 3;
        border-radius: 50%;
        background: conic-gradient(from -90deg, var(--rec-accent) var(--ring, 0%), rgba(255,255,255,0.15) 0);
        color: #fff;
        font-weight: 800;
        font-size: 1.5rem;
        display: none;
        align-items: center;
        justify-content: center;
    }

    .shutter-btn.bursting .shutter-count { display: flex; }
    .shutter-btn.bursting .shutter-core { visibility: hidden; }

    .shutter-label {
        position: absolute;
        bottom: -26px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 0.66rem;
        font-weight: 800;
        letter-spacing: 0.1em;
        color: var(--rec-dark);
        white-space: nowrap;
        text-transform: uppercase;
    }

    .cam-side-btn {
        width: 54px;
        height: 54px;
        border-radius: 50%;
        border: 1px solid #dfe7e2;
        background: #fff;
        color: var(--rec-dark);
        font-size: 1.15rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
    }

    .cam-side-btn:hover { background: var(--rec-dark); color: #fff; border-color: var(--rec-dark); }

    .cam-hint {
        text-align: center;
        font-size: 0.8rem;
        color: #8a9b91;
        margin-top: 0.5rem;
    }

    /* Flash al capturar */
    .cam-flash {
        position: absolute;
        inset: 0;
        background: #fff;
        opacity: 0;
        z-index: 8;
        pointer-events: none;
    }

    .cam-flash.fire { animation: flashFire 0.28s ease-out; }

    @keyframes flashFire {
        0% { opacity: 0.95; }
        100% { opacity: 0; }
    }

    /* Stepper de progreso de las 5 fotos */
    .burst-dots {
        display: none;
        align-items: center;
        justify-content: center;
        gap: 0;
        margin: 1.1rem auto 0;
        max-width: 280px;
        position: relative;
    }

    .burst-dots.visible { display: flex; }

    .burst-dots span {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #fff;
        border: 2px solid #d7e2da;
        color: #aab8af;
        font-size: 0.78rem;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        position: relative;
        z-index: 2;
        flex-shrink: 0;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    /* Conector entre pasos */
    .burst-dots span:not(:last-child)::after {
        content: '';
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        width: 100%;
        height: 4px;
        background: #e3ebe5;
        z-index: -1;
        transition: background 0.4s ease;
    }

    .burst-dots span:not(:last-child) { margin-right: 32px; }

    .burst-dots span.filled {
        background: linear-gradient(145deg, var(--rec-light), var(--rec-dark));
        border-color: var(--rec-dark);
        color: transparent;
        transform: scale(1.08);
        box-shadow: 0 4px 14px rgba(var(--rp), 0.45);
    }

    /* Check dorado dentro del paso completado */
    .burst-dots span.filled::before {
        content: '\2713';
        color: var(--rec-accent-soft);
        font-size: 0.9rem;
        font-weight: 900;
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .burst-dots span.filled:not(:last-child)::after { background: linear-gradient(90deg, var(--rec-light), var(--rec-primary)); }

    /* Tira de las 5 fotos capturadas */
    .filmstrip {
        display: none;
        gap: 10px;
        justify-content: center;
        margin-top: 1rem;
        flex-wrap: wrap;
    }

    .filmstrip.visible { display: flex; }

    .filmstrip .film-thumb {
        width: 62px;
        height: 62px;
        border-radius: 12px;
        object-fit: cover;
        cursor: pointer;
        border: 3px solid transparent;
        opacity: 0.65;
        transition: all 0.25s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .filmstrip .film-thumb:hover { opacity: 1; transform: translateY(-3px); }

    .filmstrip .film-thumb.active {
        border-color: var(--rec-accent);
        opacity: 1;
        transform: scale(1.08);
        box-shadow: 0 6px 16px rgba(var(--ra), 0.4);
    }

    /* ==================== Panel de resultados ==================== */
    .resultado-hero {
        background: linear-gradient(140deg, var(--rec-dark) 0%, var(--rec-dark) 55%, var(--rec-primary) 100%);
        border-radius: 18px;
        color: #fff;
        position: relative;
        overflow: hidden;
    }

    .resultado-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 5px;
        background: linear-gradient(90deg, #d52b1e 33%, #f9e300 33% 66%, #007a33 66%);
    }

    .resultado-hero::after {
        content: '';
        position: absolute;
        right: -40px;
        bottom: -60px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(var(--ra), 0.22), transparent 65%);
    }

    /* Anillo de confianza */
    .conf-ring { position: relative; width: 124px; height: 124px; }
    .conf-ring svg { transform: rotate(-90deg); }
    .conf-ring .ring-bg { stroke: rgba(255, 255, 255, 0.18); }
    .conf-ring .ring-val {
        stroke: var(--rec-accent);
        stroke-linecap: round;
        transition: stroke-dashoffset 1.3s cubic-bezier(0.4, 0, 0.2, 1);
        filter: drop-shadow(0 0 5px rgba(var(--ra), 0.55));
    }

    .conf-ring .conf-num {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }

    /* Pila de miniaturas de las 5 fotos en el resultado */
    .res-stack {
        display: flex;
        align-items: center;
    }

    .res-stack img {
        width: 46px;
        height: 46px;
        border-radius: 10px;
        object-fit: cover;
        border: 2px solid rgba(255, 255, 255, 0.65);
        box-shadow: 0 5px 14px rgba(0, 0, 0, 0.3);
        margin-left: -14px;
        transition: transform 0.2s ease;
    }

    .res-stack img:first-child { margin-left: 0; }
    .res-stack img:hover { transform: translateY(-5px) scale(1.12); z-index: 2; position: relative; }

    /* ==================== Overlay de análisis con GIF ==================== */
    .analisis-overlay {
        position: absolute;
        inset: 0;
        background: rgba(252, 254, 253, 0.97);
        backdrop-filter: blur(8px);
        z-index: 50;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: inherit;
    }

    .analisis-box { min-width: 270px; max-width: 340px; text-align: center; }

    .analisis-gif {
        width: 80px;
        height: 80px;
        object-fit: contain;
        margin: 0 auto 0.25rem;
        display: block;
        filter: drop-shadow(0 8px 24px rgba(99, 102, 241, 0.25));
    }

    .analisis-titulo {
        font-weight: 800;
        font-size: 1.05rem;
        background: linear-gradient(90deg, var(--rec-dark), #6366f1, #a855f7);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
        animation: tituloShine 2.4s linear infinite;
        background-size: 200% auto;
    }

    @keyframes tituloShine {
        to { background-position: 200% center; }
    }

    .analisis-foto-progreso {
        font-size: 0.78rem;
        font-weight: 700;
        color: #6366f1;
        margin-bottom: 0.9rem;
    }

    .analisis-step {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 7px 0;
        color: #9aa7a0;
        font-size: 0.86rem;
        font-weight: 600;
        text-align: left;
        transition: color 0.3s ease;
    }

    .analisis-step .st-ico {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #eef3ef;
        color: #9aa7a0;
        font-size: 0.85rem;
        flex-shrink: 0;
        transition: all 0.3s ease;
    }

    .analisis-step.active { color: var(--rec-dark); }
    .analisis-step.active .st-ico {
        background: linear-gradient(135deg, #6366f1, #a855f7);
        color: #fff;
        animation: stepPulse 1s ease-in-out infinite;
    }

    .analisis-step.done { color: var(--rec-primary); }
    .analisis-step.done .st-ico { background: #d9efe2; color: var(--rec-primary); }

    @keyframes stepPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4); }
        50%      { box-shadow: 0 0 0 9px rgba(99, 102, 241, 0); }
    }

    .badge-preliminar {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.10), rgba(168, 85, 247, 0.10));
        color: #6366f1;
        border: 1px solid rgba(99, 102, 241, 0.35);
        font-size: 0.72rem;
        font-weight: 700;
        padding: 6px 14px;
        border-radius: 30em;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .fade-up { animation: fadeUp 0.5s ease forwards; }

    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(14px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* ==================== Resultado de la red neuronal ==================== */
    .badge-preliminar.ia-inactiva {
        background: rgba(213, 43, 30, 0.08);
        color: #b42318;
        border-color: rgba(213, 43, 30, 0.35);
    }

    .estado-badge { letter-spacing: 0.02em; }
    .estado-confirmado     { background: #fff; color: #1a7f37; }
    .estado-probable       { background: #fff4d6; color: #8a5a00; }
    .estado-no_concluyente { background: #eef1f4; color: #475569; }
    .estado-rechazado      { background: #fde8e8; color: #b42318; }

    /* Botón que abre el análisis completo (mismo verde del modal) */
    .btn-ver-analisis {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        border: 0;
        border-radius: 14px;
        padding: 0.85rem 1.1rem;
        background: #0b8a5f;
        color: #fff;
        font-weight: 700;
        font-size: 0.9rem;
        box-shadow: 0 10px 24px rgba(11, 138, 95, 0.28);
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .btn-ver-analisis:hover { background: #0a7a54; transform: translateY(-2px); box-shadow: 0 14px 30px rgba(11, 138, 95, 0.36); }
    .btn-ver-analisis:focus-visible { outline: 3px solid rgba(18, 184, 134, 0.45); outline-offset: 2px; }

    /* ==================== Modal «Análisis completo» ====================
       A la izquierda, el resultado, las secciones y la probabilidad por tipo; a la
       derecha, las fotos agrupadas en «Coinciden / No coinciden», las métricas y el
       informe. Verde = coincide, rojo = no coincide, ámbar = aviso. Fondos, bordes y
       textos salen del tema, así se ve bien en modo claro y en modo oscuro. */
    .coca-modal {
        --cm-bg: var(--custom-white, #fff);
        --cm-lateral: var(--default-background, #f7f8fa);
        --cm-borde: var(--default-border, #e6ebf1);
        --cm-texto: var(--default-text-color, #191919);
        --cm-tenue: var(--text-muted, #64748b);
        --cm-pista: color-mix(in srgb, var(--cm-texto) 9%, transparent);
        --cm-hover: color-mix(in srgb, var(--cm-texto) 5%, transparent);
        --cm-ok: #12b886;
        --cm-ok-fondo: #0b8a5f;
        --cm-ok-texto: #087f5b;
        --cm-mal: #ef4444;
        --cm-mal-fondo: #d92d20;
        --cm-mal-texto: #c81e1e;
        --cm-alerta: #f59e0b;
        --cm-alerta-texto: #a16207;
        --cm-neutro: #94a3b8;
        --cm-neutro-texto: #475569;
    }

    [data-theme-mode="dark"] .coca-modal {
        --cm-ok-texto: #34d399;
        --cm-mal-texto: #f87171;
        --cm-alerta-texto: #fbbf24;
        --cm-neutro-texto: #cbd5e1;
    }

    .coca-modal .modal-content.cm-app {
        border: 0;
        background: var(--cm-bg);
        color: var(--cm-texto);
    }

    .cm-min0 { min-width: 0; }

    /* ---- Barra lateral ---- */
    .cm-marca { display: flex; align-items: flex-start; gap: 12px; }

    .cm-marca-ico {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        background: rgba(18, 184, 134, 0.14);
        color: var(--cm-ok-texto);
    }

    .cm-marca-ico.t-alerta { background: rgba(245, 158, 11, 0.16); color: var(--cm-alerta-texto); }
    .cm-marca-ico.t-neutro { background: rgba(148, 163, 184, 0.2); color: var(--cm-neutro-texto); }
    .cm-marca-ico.t-mal    { background: rgba(239, 68, 68, 0.13); color: var(--cm-mal-texto); }

    .cm-titulo {
        font-size: 1.3rem;
        font-weight: 700;
        line-height: 1.2;
        margin: 2px 0 0;
        color: var(--cm-texto);
        overflow-wrap: anywhere;
    }

    .cm-subtitulo {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px 8px;
        margin-top: 7px;
        font-size: 0.78rem;
        color: var(--cm-tenue);
    }

    .cm-estado {
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        padding: 3px 10px;
        border-radius: 99px;
    }

    .cm-estado.is-confirmado     { background: rgba(18, 184, 134, 0.15); color: var(--cm-ok-texto); }
    .cm-estado.is-probable       { background: rgba(245, 158, 11, 0.16); color: var(--cm-alerta-texto); }
    .cm-estado.is-no_concluyente { background: rgba(148, 163, 184, 0.22); color: var(--cm-neutro-texto); }
    .cm-estado.is-rechazado      { background: rgba(239, 68, 68, 0.14); color: var(--cm-mal-texto); }

    .cm-nav { display: flex; flex-direction: column; gap: 4px; }

    .cm-nav-item {
        display: flex;
        align-items: center;
        gap: 11px;
        width: 100%;
        border: 0;
        border-radius: 12px;
        padding: 10px 12px;
        background: transparent;
        color: var(--cm-texto);
        font-size: 0.9rem;
        font-weight: 500;
        text-align: left;
        transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
    }

    .cm-nav-item i { font-size: 1.15rem; opacity: 0.7; }
    .cm-nav-item:hover { background: var(--cm-hover); }
    .cm-nav-item:focus-visible { outline: 3px solid rgba(18, 184, 134, 0.45); outline-offset: 1px; }
    .cm-nav-item.active { background: var(--cm-ok-fondo); color: #fff; font-weight: 600; box-shadow: 0 8px 18px rgba(11, 138, 95, 0.28); }
    .cm-nav-item.active i { opacity: 1; }

    .cm-nav-num {
        margin-left: auto;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 1px 8px;
        border-radius: 99px;
        background: var(--cm-pista);
    }

    .cm-nav-item.active .cm-nav-num { background: rgba(255, 255, 255, 0.22); }

    .cm-side-label {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--cm-tenue);
        margin: 0 10px 6px;
    }

    .cm-clases { display: flex; flex-direction: column; gap: 2px; }
    .cm-fila { padding: 8px 10px; border-radius: 12px; }
    .cm-fila.es-general { background: var(--cm-hover); padding: 11px 12px; }

    .cm-fila-top {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 8px;
        margin-bottom: 6px;
        font-size: 0.82rem;
    }

    .cm-fila-top > span { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cm-fila-top > b { flex-shrink: 0; font-size: 0.78rem; font-weight: 600; color: var(--cm-tenue); }
    .cm-fila.es-ganadora .cm-fila-top > b { color: var(--cm-texto); }

    .cm-barra {
        display: flex;
        height: 5px;
        border-radius: 99px;
        background: var(--cm-pista);
        overflow: hidden;
    }

    .cm-barra > span { display: block; height: 100%; width: 0; transition: width 1s cubic-bezier(0.4, 0, 0.2, 1); }
    .cm-barra > .t-ok     { background: var(--cm-ok); }
    .cm-barra > .t-mal    { background: var(--cm-mal); }
    .cm-barra > .t-alerta { background: var(--cm-alerta); }
    .cm-barra > .t-neutro { background: var(--cm-neutro); }
    .cm-barra > .t-suave  { background: color-mix(in srgb, var(--cm-ok) 45%, transparent); }

    .cm-ver-mas {
        align-self: flex-start;
        border: 0;
        background: transparent;
        color: var(--cm-ok-texto);
        font-size: 0.78rem;
        font-weight: 600;
        padding: 6px 10px;
        border-radius: 8px;
    }

    .cm-ver-mas:hover { background: var(--cm-hover); }

    .cm-resumen { margin: 0; font-size: 0.84rem; line-height: 1.55; color: var(--cm-texto); }
    .cm-resumen .ok     { color: var(--cm-ok-texto); }
    .cm-resumen .mal    { color: var(--cm-mal-texto); }
    .cm-resumen .alerta { color: var(--cm-alerta-texto); }

    /* ---- Contenido ---- */
    .cm-main { position: relative; min-width: 0; }

    .cm-cabecera {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .cm-seccion-titulo { font-size: 1.2rem; font-weight: 700; margin: 0; color: var(--cm-texto); }
    .cm-seccion-sub { font-size: 0.78rem; color: var(--cm-tenue); margin-top: 2px; }
    .cm-acciones { display: flex; align-items: center; gap: 8px; margin-left: auto; }

    .cm-vista { display: inline-flex; padding: 3px; border-radius: 12px; background: var(--cm-pista); }

    .cm-vista button {
        border: 0;
        background: transparent;
        color: var(--cm-tenue);
        font-size: 0.8rem;
        font-weight: 600;
        padding: 7px 13px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
    }

    .cm-vista button.active { background: var(--cm-bg); color: var(--cm-texto); box-shadow: 0 1px 4px rgba(15, 23, 42, 0.14); }

    .cm-cerrar {
        width: 38px;
        height: 38px;
        flex-shrink: 0;
        border: 0;
        border-radius: 50%;
        background: var(--cm-pista);
        color: var(--cm-texto);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        transition: background 0.2s ease;
    }

    .cm-cerrar:hover { background: color-mix(in srgb, var(--cm-texto) 15%, transparent); }

    /* Grupos de fotos y mosaicos */
    .cm-grupo + .cm-grupo { margin-top: 24px; }

    .cm-grupo-titulo {
        display: flex;
        align-items: baseline;
        gap: 7px;
        margin: 0 0 12px;
        font-size: 0.98rem;
        font-weight: 700;
        color: var(--cm-texto);
    }

    .cm-grupo-titulo small { font-size: 0.86rem; font-weight: 500; color: var(--cm-tenue); }

    .cm-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }

    .cm-tile {
        position: relative;
        display: block;
        width: 100%;
        aspect-ratio: 1 / 1;
        padding: 0;
        border: 0;
        border-radius: 16px;
        overflow: hidden;
        background: #0f1a14;
        cursor: zoom-in;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.12);
        opacity: 0;
        transform: translateY(10px) scale(0.98);
        animation: cmEntrar 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        animation-delay: calc(var(--i, 0) * 70ms);
        transition: box-shadow 0.25s ease;
    }

    .cm-tile:hover { box-shadow: 0 14px 32px rgba(15, 23, 42, 0.22); }
    .cm-tile:focus-visible { outline: 3px solid var(--cm-ok); outline-offset: 2px; }

    @keyframes cmEntrar { to { opacity: 1; transform: none; } }

    .cm-tile img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: opacity 0.35s ease, transform 0.5s ease;
    }

    .cm-tile:hover img { transform: scale(1.05); }

    /* Degradado inferior para que la etiqueta se lea sobre cualquier foto */
    .cm-tile::after {
        content: '';
        position: absolute;
        inset: auto 0 0 0;
        height: 45%;
        background: linear-gradient(to top, rgba(0, 0, 0, 0.45), transparent);
        pointer-events: none;
    }

    .cm-img-mapa { opacity: 0; }
    .cm-grupos.ver-mapa .cm-img-mapa,
    .cm-visor-img.ver-mapa .cm-img-mapa { opacity: 1; }
    .cm-grupos.ver-mapa .cm-tile .cm-img-foto { opacity: 0; }

    .cm-sin-foto {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: rgba(255, 255, 255, 0.45);
        font-size: 2rem;
    }

    .cm-etiqueta {
        position: absolute;
        left: 10px;
        bottom: 10px;
        z-index: 2;
        max-width: calc(100% - 20px);
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 10px;
        border-radius: 9px;
        background: var(--cm-ok-fondo);
        color: #fff;
        font-size: 0.76rem;
        font-weight: 600;
        white-space: nowrap;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .cm-etiqueta > span { overflow: hidden; text-overflow: ellipsis; }
    .cm-etiqueta > i { flex-shrink: 0; }
    .cm-etiqueta.es-mal  { background: var(--cm-mal-fondo); }
    .cm-etiqueta.es-duda { background: #fbbf24; color: #3b2600; }
    .cm-etiqueta.en-linea { position: static; font-size: 0.86rem; padding: 6px 12px; box-shadow: none; }

    .cm-chip {
        position: absolute;
        top: 9px;
        z-index: 2;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 99px;
        background: rgba(15, 23, 42, 0.55);
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
    }

    .cm-chip.izq { left: 9px; }
    .cm-chip.der { right: 9px; }
    .cm-chip.ajena { background: rgba(245, 158, 11, 0.94); color: #3b2600; }

    .cm-leyenda { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin: 18px 0 0; font-size: 0.78rem; color: var(--cm-tenue); }

    .cm-escala {
        width: 110px;
        height: 8px;
        border-radius: 99px;
        background: linear-gradient(90deg, #00008f, #0050ff, #00e5ff, #ffee00, #ff3c00, #8f0000);
    }

    .cm-vacio { color: var(--cm-tenue); font-size: 0.86rem; margin: 0; }

    /* Métricas */
    .cm-metricas { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }

    .cm-metrica {
        border: 1px solid var(--cm-borde);
        border-radius: 16px;
        padding: 16px 18px;
        background: var(--cm-bg);
        opacity: 0;
        animation: cmEntrar 0.45s ease forwards;
        animation-delay: calc(var(--i, 0) * 60ms);
    }

    .cm-metrica-top { display: flex; align-items: center; gap: 12px; }

    .cm-metrica-ico {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    .cm-metrica-ico.t-ok     { background: rgba(18, 184, 134, 0.13); color: var(--cm-ok-texto); }
    .cm-metrica-ico.t-alerta { background: rgba(245, 158, 11, 0.15); color: var(--cm-alerta-texto); }
    .cm-metrica-ico.t-mal    { background: rgba(239, 68, 68, 0.12); color: var(--cm-mal-texto); }

    .cm-metrica-nombre { font-size: 0.84rem; font-weight: 600; }
    .cm-metrica-nivel { font-size: 0.72rem; font-weight: 700; }
    .cm-metrica-valor { margin-left: auto; font-size: 1.55rem; font-weight: 700; line-height: 1; }
    .cm-metrica .cm-barra { height: 7px; margin: 14px 0 8px; }
    .cm-metrica p { margin: 0; font-size: 0.76rem; color: var(--cm-tenue); }

    .cm-tono-ok     { color: var(--cm-ok-texto); }
    .cm-tono-alerta { color: var(--cm-alerta-texto); }
    .cm-tono-mal    { color: var(--cm-mal-texto); }

    /* Informe */
    .cm-veredicto {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
        border-radius: 14px;
        margin-bottom: 14px;
        font-size: 0.88rem;
        font-weight: 500;
    }

    .cm-veredicto i { font-size: 1.3rem; flex-shrink: 0; margin-top: 1px; }
    .cm-veredicto.is-confirmado     { background: rgba(18, 184, 134, 0.11); color: var(--cm-ok-texto); }
    .cm-veredicto.is-probable       { background: rgba(245, 158, 11, 0.13); color: var(--cm-alerta-texto); }
    .cm-veredicto.is-no_concluyente { background: rgba(148, 163, 184, 0.16); color: var(--cm-neutro-texto); }
    .cm-veredicto.is-rechazado      { background: rgba(239, 68, 68, 0.1); color: var(--cm-mal-texto); }

    .cm-seccion {
        border: 1px solid var(--cm-borde);
        border-radius: 16px;
        padding: 16px 18px;
        margin-bottom: 14px;
    }

    .cm-seccion.ia {
        border-color: rgba(99, 102, 241, 0.28);
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.06), rgba(168, 85, 247, 0.06));
    }

    .cm-seccion.alerta { border-color: rgba(245, 158, 11, 0.35); background: rgba(245, 158, 11, 0.06); }

    .cm-seccion h6 {
        display: flex;
        align-items: center;
        gap: 7px;
        margin: 0 0 10px;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--cm-tenue);
    }

    .cm-seccion.ia h6 i { color: #6366f1; }
    .cm-seccion p { margin: 0; font-size: 0.88rem; line-height: 1.65; }

    .cm-lista { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; font-size: 0.85rem; }
    .cm-lista li { display: flex; align-items: flex-start; gap: 9px; }
    .cm-lista li i { flex-shrink: 0; margin-top: 2px; }
    .cm-lista.ok li i { color: var(--cm-ok); }
    .cm-seccion.alerta .cm-lista li i, .cm-visor-avisos li i { color: var(--cm-alerta); }

    .cm-tecnico { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 0; }
    .cm-tecnico > div { background: var(--cm-hover); border-radius: 12px; padding: 10px 12px; }

    .cm-tecnico dt {
        font-size: 0.68rem;
        font-weight: 600;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: var(--cm-tenue);
    }

    .cm-tecnico dd { margin: 3px 0 0; font-size: 0.9rem; font-weight: 700; overflow-wrap: anywhere; }

    .cm-pie { display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--cm-borde); background: var(--cm-bg); }
    .cm-btn { border-radius: 12px; padding: 0.6rem 1.15rem; font-weight: 600; }
    .cm-btn-ok { background: var(--cm-ok-fondo); color: #fff; border: 0; }
    .cm-btn-ok:hover, .cm-btn-ok:focus-visible { background: #0a7a54; color: #fff; }

    /* Detalle de una foto */
    .cm-visor {
        display: flex;
        flex-direction: column;
        background: var(--cm-bg);
        z-index: 6;
        animation: cmVisorEntrar 0.25s ease;
    }

    @keyframes cmVisorEntrar {
        from { opacity: 0; transform: scale(0.985); }
        to   { opacity: 1; transform: none; }
    }

    .cm-visor-cab { display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--cm-borde); }
    .cm-visor-cab h4 { flex: 1; margin: 0; font-size: 1.05rem; font-weight: 700; }

    .cm-visor-nav {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        border: 1px solid var(--cm-borde);
        background: var(--cm-bg);
        color: var(--cm-texto);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
    }

    .cm-visor-nav:disabled { opacity: 0.35; }
    .cm-visor-cuerpo { flex: 1; min-height: 0; overflow-y: auto; display: grid; gap: 24px; align-content: start; }

    .cm-visor-img {
        position: relative;
        aspect-ratio: 1 / 1;
        border-radius: 18px;
        overflow: hidden;
        background: #0f1a14;
    }

    .cm-visor-img img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: opacity 0.35s ease; }
    .cm-visor-img.ver-mapa #cmVisorFoto { opacity: 0; }

    .cm-vista-flotante {
        position: absolute;
        left: 50%;
        bottom: 12px;
        transform: translateX(-50%);
        z-index: 2;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
    }

    .cm-vista-flotante button { color: rgba(255, 255, 255, 0.85); white-space: nowrap; }
    .cm-vista-flotante button.active { background: #fff; color: #0f172a; }

    .cm-visor-texto { margin: 12px 0 18px; font-size: 0.88rem; line-height: 1.55; }

    /* Escritorio: dos columnas con alto fijo; cada lado se desplaza por su cuenta */
    @media (min-width: 992px) {
        .coca-modal .modal-dialog { --bs-modal-width: min(1120px, calc(100vw - 2.5rem)); }

        .coca-modal .modal-content.cm-app {
            flex-direction: row;
            height: min(760px, calc(100vh - 3.5rem));
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 30px 90px rgba(15, 23, 42, 0.3);
        }

        .cm-side {
            width: 290px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 26px 18px 22px;
            background: var(--cm-lateral);
            border-right: 1px solid var(--cm-borde);
            overflow-y: auto;
        }

        .cm-marca { padding: 0 6px; }
        .cm-resumen { margin-top: auto; padding: 4px 6px 0; }

        .cm-main { flex: 1; display: flex; flex-direction: column; }
        .cm-cabecera { padding: 24px 28px 14px; }
        .cm-cuerpo { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 4px 28px 26px; }
        .cm-pie { padding: 14px 28px; }

        .cm-visor { position: absolute; inset: 0; }
        .cm-visor-cab { padding: 18px 24px; }
        .cm-visor-cuerpo { grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); padding: 22px 24px; }
    }

    @media (min-width: 992px) and (max-width: 1199.98px) {
        .cm-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .cm-tecnico { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    /* Celular y tablet: pantalla completa con un solo desplazamiento,
       pestañas fijas arriba y botones fijos abajo */
    @media (max-width: 991.98px) {
        .coca-modal .modal-content.cm-app {
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .cm-side { display: contents; }
        .cm-app > * , .cm-side > * { flex-shrink: 0; }

        .cm-marca { order: 1; padding: 16px 16px 10px; align-items: center; }
        .cm-titulo { font-size: 1.15rem; }
        .cm-fila.es-general { order: 2; margin: 0 16px; }
        .cm-resumen { order: 3; padding: 10px 16px 4px; font-size: 0.82rem; }
        .cm-clases-box { display: none; }

        .cm-nav {
            order: 4;
            position: sticky;
            top: 0;
            z-index: 4;
            flex-direction: row;
            gap: 6px;
            margin-top: 10px;
            padding: 8px 16px;
            background: var(--cm-bg);
            border-bottom: 1px solid var(--cm-borde);
        }

        .cm-nav-item { flex: 1; justify-content: center; gap: 7px; padding: 9px 6px; font-size: 0.84rem; }
        .cm-nav-num { margin-left: 0; }

        .cm-main { order: 5; flex: 1 0 auto; display: flex; flex-direction: column; }
        .cm-cabecera { padding: 16px 16px 12px; }
        .cm-cuerpo { flex: 1 0 auto; padding: 0 16px 20px; }
        .cm-pie { position: sticky; bottom: 0; z-index: 4; padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); }
        .cm-pie .cm-btn { flex: 1; }

        .cm-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .cm-metricas { grid-template-columns: 1fr; }
        .cm-tecnico { grid-template-columns: repeat(2, minmax(0, 1fr)); }

        .cm-visor { position: fixed; inset: 0; z-index: 1065; }
        .cm-visor-cab { padding: 12px 16px; }
        .cm-visor-cuerpo { grid-template-columns: 1fr; gap: 16px; padding: 16px 16px calc(20px + env(safe-area-inset-bottom)); }
        .cm-visor-img { max-width: 520px; width: 100%; margin: 0 auto; }
    }

    @media (max-width: 575.98px) {
        .cm-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .cm-vista button span { display: none; }
        .cm-vista button { padding: 7px 11px; }
    }

    @media (prefers-reduced-motion: reduce) {
        .cm-tile, .cm-metrica { animation: none; opacity: 1; transform: none; }
        .cm-barra > span { transition: none; }
        .cm-visor { animation: none; }
    }

    /* Con el modal abierto, el botón flotante del asistente no tapa el contenido */
    body.coca-modal-abierto .adep-bot-launcher,
    body.coca-modal-abierto .adep-bot-nudge,
    body.coca-modal-abierto .adep-bot-panel { display: none !important; }

    @media (max-width: 575.98px) {
        .cam-stage { aspect-ratio: 3 / 4; border-radius: 14px; }
        .shutter-btn { width: 72px; height: 72px; }
        .cam-side-btn { width: 48px; height: 48px; }
        .filmstrip .film-thumb { width: 52px; height: 52px; }
    }
</style>
@endsection

@section('content')
<div class="coca-reco">
<div class="d-flex align-items-center justify-content-between page-header-breadcrumb flex-wrap gap-2">
    <div>
        <nav>
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="{{ route('dashboard.primary') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Reconocimiento de Coca</li>
            </ol>
        </nav>
        <h1 class="page-title fw-semibold fs-20 mb-0">Reconocimiento de Hoja de Coca</h1>
        <p class="text-muted mb-0">Captura 5 fotos con tu cámara y obtén un análisis al instante</p>
    </div>
    <span class="badge-preliminar {{ $apiActiva ? '' : 'ia-inactiva' }}">
        @if ($apiActiva)
            <i class="ti ti-sparkles"></i>Red neuronal YOLO26 · IA activa
        @else
            <i class="ti ti-alert-triangle"></i>Servicio de IA no disponible
        @endif
    </span>
</div>

<div class="row g-4 mt-2">
    <!-- ==================== Panel de cámara ==================== -->
    <div class="col-xl-6">
        <div class="card custom-card cam-card h-100">
            <div class="card-header justify-content-between">
                <div class="card-title mb-0">
                    <span class="avatar avatar-sm bg-primary-transparent rounded-circle me-2"><i class="ti ti-camera text-primary"></i></span>
                    Captura de hoja de coca
                </div>
                <span class="badge bg-light text-muted border fs-11" id="camResBadge"></span>
            </div>
            <div class="card-body">
                <div class="cam-stage" id="camStage">
                    <video id="camVideo" autoplay playsinline muted class="d-none"></video>
                    <canvas id="camPhoto" class="cam-photo d-none"></canvas>

                    <div class="scan-frame d-none" id="scanFrame">
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <div class="cam-flash" id="camFlash"></div>

                    <!-- GIF de IA sobre la foto mientras analiza -->
                    <div class="stage-ia">
                        <img src="{{ asset('build/assets/images/sis_logos/efecto-ia.gif') }}" alt="IA analizando">
                        <span class="stage-ia-text">Reconociendo hoja de coca…</span>
                    </div>

                    <span class="cam-live-chip"><span class="dot"></span>EN VIVO</span>
                    <span class="burst-chip"><i class="ti ti-camera"></i><span id="burstChipText">Foto 1 de 5</span></span>
                    <button type="button" class="cam-flip" id="camFlipBtn" title="Cambiar cámara">
                        <i class="ti ti-refresh"></i>
                    </button>

                    <!-- Estado inicial -->
                    <div class="cam-idle" id="camIdle">
                        <div class="cam-idle-icon">
                            <i class="ti ti-camera fs-32" style="color: var(--rec-light);"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-1 text-white">Activa tu cámara</h5>
                            <p class="mb-0 fs-13" style="max-width: 310px;">Toma <strong style="color: var(--rec-accent);">5 fotos de la hoja</strong> desde distintos ángulos para un análisis más preciso. Enfócala dentro del marco.</p>
                        </div>
                        <button type="button" class="btn btn-success btn-wave px-4" id="camStartBtn">
                            <i class="ti ti-video me-2"></i>Encender cámara
                        </button>
                    </div>

                    <!-- Error / permiso denegado -->
                    <div class="cam-idle d-none" id="camError">
                        <div class="cam-idle-icon" style="background: rgba(213,43,30,0.14); border-color: rgba(213,43,30,0.4);">
                            <i class="ti ti-camera-off fs-32 text-danger"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-1 text-white">No se pudo acceder a la cámara</h5>
                            <p class="mb-0 fs-13" style="max-width: 320px;" id="camErrorMsg">Revisa que el navegador tenga permiso para usar la cámara.</p>
                        </div>
                        <button type="button" class="btn btn-light btn-wave px-4" id="camRetryBtn">
                            <i class="ti ti-reload me-2"></i>Reintentar
                        </button>
                    </div>
                </div>

                <!-- Stepper de progreso de las 5 fotos -->
                <div class="burst-dots" id="burstDots">
                    <span>1</span><span>2</span><span>3</span><span>4</span><span>5</span>
                </div>

                <!-- Tira de fotos capturadas -->
                <div class="filmstrip" id="filmstrip"></div>

                <!-- Controles en vivo -->
                <div class="cam-controls" id="camControlsLive" style="display:none;">
                    <button type="button" class="cam-side-btn" id="camOffBtn" title="Apagar cámara">
                        <i class="ti ti-x"></i>
                    </button>
                    <button type="button" class="shutter-btn" id="shutterBtn" title="Tomar foto">
                        <span class="shutter-core"><i class="ti ti-camera"></i></span>
                        <span class="shutter-count" id="shutterCount">1</span>
                        <span class="shutter-label" id="shutterLabel">Foto 1 de 5</span>
                    </button>
                    <button type="button" class="cam-side-btn" id="undoBtn" title="Borrar última foto" style="visibility:hidden;">
                        <i class="ti ti-arrow-back-up"></i>
                    </button>
                </div>

                <!-- Controles con fotos listas -->
                <div class="cam-controls" id="camControlsPhoto" style="display:none;">
                    <button type="button" class="btn btn-light btn-wave px-4" id="retakeBtn">
                        <i class="ti ti-camera-rotate me-2"></i>Repetir
                    </button>
                    <button type="button" class="btn btn-primary btn-lg btn-wave px-4" id="analizarBtn">
                        <i class="ti ti-scan me-2"></i>Analizar 5 fotos
                    </button>
                </div>

                <p class="cam-hint" id="camHint">
                    <i class="ti ti-bulb me-1"></i>Consejo: usa luz natural y coloca la hoja sobre un fondo claro
                </p>
            </div>
        </div>
    </div>

    <!-- ==================== Panel de resultados ==================== -->
    <div class="col-xl-6">
        <div class="card custom-card cam-card h-100 position-relative" id="resultadoCard">
            <div class="card-header justify-content-between">
                <div class="card-title mb-0">
                    <span class="avatar avatar-sm bg-success-transparent rounded-circle me-2"><i class="ti ti-report-analytics text-success"></i></span>
                    Resultado del análisis
                </div>
                <button type="button" class="btn btn-sm btn-light d-none" id="nuevaCapturaBtn">
                    <i class="ti ti-camera me-1"></i>Nueva captura
                </button>
            </div>
            <div class="card-body">
                <!-- Placeholder -->
                <div id="resultadoPlaceholder" class="text-center py-5 h-100 d-flex flex-column justify-content-center align-items-center">
                    <div class="avatar avatar-xxl bg-success-transparent rounded-circle mb-3">
                        <i class="ti ti-leaf fs-32 text-success opacity-75"></i>
                    </div>
                    <h5 class="text-muted fw-semibold">Esperando capturas</h5>
                    <p class="text-muted mb-0 mx-auto" style="max-width: 330px;">Enciende la cámara y toma 5 fotos de la hoja de coca. Aquí verás el análisis combinado de todas las capturas.</p>
                </div>

                <!-- Resultado -->
                <div id="resultadoContenido" class="d-none">
                    <div class="resultado-hero p-4 mb-4">
                        <div class="d-flex align-items-center gap-3 flex-wrap position-relative" style="z-index:1;">
                            <div class="conf-ring flex-shrink-0">
                                <svg width="124" height="124" viewBox="0 0 124 124">
                                    <circle class="ring-bg" cx="62" cy="62" r="54" fill="none" stroke-width="9"/>
                                    <circle class="ring-val" cx="62" cy="62" r="54" fill="none" stroke-width="9"
                                            stroke-dasharray="339.3" stroke-dashoffset="339.3" id="confRing"/>
                                </svg>
                                <div class="conf-num">
                                    <span class="fs-24 fw-bold" id="resConfianza">0%</span>
                                    <span class="fs-10 text-uppercase opacity-75">confianza</span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="text-uppercase fw-bold fs-10 mb-1 opacity-75">Clasificación estimada</h6>
                                <h4 class="mb-2 fw-bold text-white" id="resTipo">--</h4>
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                    <span class="badge bg-white text-success fw-bold px-3 py-2 rounded-pill shadow-sm" id="resCalidad">--</span>
                                    <span class="badge fs-10" style="background: rgba(255,255,255,0.16); color: var(--rec-accent-soft);" id="resHora"></span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="res-stack" id="resStack"></div>
                                    <span class="fs-11 opacity-75" id="resCapturas">5 capturas analizadas</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert border-0 shadow-sm d-flex align-items-start gap-2 mb-3 fs-12" id="resMensaje">
                        <i class="ti ti-info-circle fs-16 mt-1 flex-shrink-0"></i>
                        <span></span>
                    </div>

                    <!-- Todo el detalle (fotos, métricas, mapas de calor e informe) va en el modal -->
                    <button type="button" class="btn-ver-analisis" id="verAnalisisBtn">
                        <i class="ti ti-layout-grid fs-18"></i>Ver análisis completo<i class="ti ti-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- Overlay de análisis con GIF -->
            <div class="analisis-overlay d-none" id="analisisOverlay">
                <div class="analisis-box">
                    <img src="{{ asset('build/assets/images/sis_logos/efecto-ia.gif') }}" alt="Analizando con IA" class="analisis-gif">
                    <div class="analisis-titulo mb-1">Analizando hoja de coca</div>
                    <div class="analisis-foto-progreso" id="analisisFotoProgreso">Procesando captura 1 de 5…</div>
                    <div class="analisis-step" data-step="1">
                        <span class="st-ico"><i class="ti ti-scan"></i></span>Preparando las capturas
                    </div>
                    <div class="analisis-step" data-step="2">
                        <span class="st-ico"><i class="ti ti-cloud-upload"></i></span>Enviando al servidor de IA
                    </div>
                    <div class="analisis-step" data-step="3">
                        <span class="st-ico"><i class="ti ti-brain"></i></span>Red neuronal analizando la hoja
                    </div>
                    <div class="analisis-step" data-step="4">
                        <span class="st-ico"><i class="ti ti-arrows-diff"></i></span>Comparando consistencia entre fotos
                    </div>
                    <div class="analisis-step" data-step="5">
                        <span class="st-ico"><i class="ti ti-report"></i></span>Generando informe
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Consejos -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card custom-card border-0 shadow-sm bg-primary-transparent mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="avatar avatar-lg bg-primary text-white rounded-circle flex-shrink-0">
                        <i class="ti ti-photo-sensor-3 fs-22"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="fw-bold mb-1 text-primary">¿Cómo lograr una buena captura?</h5>
                        <div class="row g-2 mt-1">
                            <div class="col-sm-6 col-lg-3 d-flex align-items-center gap-2">
                                <i class="ti ti-sun text-warning fs-18"></i><span class="fs-13 text-muted">Luz natural, sin sombras fuertes</span>
                            </div>
                            <div class="col-sm-6 col-lg-3 d-flex align-items-center gap-2">
                                <i class="ti ti-square text-success fs-18"></i><span class="fs-13 text-muted">Fondo claro y uniforme</span>
                            </div>
                            <div class="col-sm-6 col-lg-3 d-flex align-items-center gap-2">
                                <i class="ti ti-focus-centered text-primary fs-18"></i><span class="fs-13 text-muted">Hoja centrada en el marco</span>
                            </div>
                            <div class="col-sm-6 col-lg-3 d-flex align-items-center gap-2">
                                <i class="ti ti-angle text-danger fs-18"></i><span class="fs-13 text-muted">Toma las 5 fotos desde distintos ángulos</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
@endsection

@section('modals')
<!-- ==================== Modal: análisis completo de la red neuronal ==================== -->
<div class="modal fade coca-modal" id="cocaResultadoModal" tabindex="-1" aria-labelledby="cmTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-lg-down">
        <div class="modal-content cm-app">
            <!-- Lateral: resultado, secciones y probabilidad por tipo -->
            <aside class="cm-side">
                <div class="cm-marca">
                    <span class="cm-marca-ico" id="cmMarcaIco"><i class="ti ti-leaf"></i></span>
                    <div class="cm-min0 flex-grow-1">
                        <h2 class="cm-titulo" id="cmTitulo">—</h2>
                        <div class="cm-subtitulo">
                            <span class="cm-estado" id="cmEstado"></span>
                            <span id="cmConfianza"></span>
                        </div>
                    </div>
                    <button type="button" class="cm-cerrar d-lg-none" data-bs-dismiss="modal" aria-label="Cerrar"><i class="ti ti-x"></i></button>
                </div>

                <nav class="cm-nav" role="tablist" aria-label="Secciones del análisis">
                    <button type="button" class="cm-nav-item active" role="tab" id="cmTabFotos" aria-controls="cmPanelFotos" aria-selected="true" data-cm-tab="fotos">
                        <i class="ti ti-photo"></i><span>Fotos</span><b class="cm-nav-num" id="cmNavFotos">0</b>
                    </button>
                    <button type="button" class="cm-nav-item" role="tab" id="cmTabMetricas" aria-controls="cmPanelMetricas" aria-selected="false" tabindex="-1" data-cm-tab="metricas">
                        <i class="ti ti-chart-bar"></i><span>Métricas</span>
                    </button>
                    <button type="button" class="cm-nav-item" role="tab" id="cmTabInforme" aria-controls="cmPanelInforme" aria-selected="false" tabindex="-1" data-cm-tab="informe">
                        <i class="ti ti-brain"></i><span>Informe</span>
                    </button>
                </nav>

                <div class="cm-fila es-general" id="cmGeneral"></div>

                <div class="cm-clases-box">
                    <div class="cm-side-label">Probabilidad por tipo</div>
                    <div class="cm-clases" id="cmClases"></div>
                    <button type="button" class="cm-ver-mas d-none" id="cmVerMas"></button>
                </div>

                <p class="cm-resumen" id="cmResumen"></p>
            </aside>

            <!-- Contenido de la sección elegida -->
            <section class="cm-main">
                <header class="cm-cabecera">
                    <div class="cm-min0">
                        <h3 class="cm-seccion-titulo" id="cmSeccion">Fotos analizadas</h3>
                        <div class="cm-seccion-sub" id="cmHora"></div>
                    </div>
                    <div class="cm-acciones">
                        <div class="cm-vista" id="cmVistaControl" role="group" aria-label="Ver las fotos o el mapa de calor">
                            <button type="button" class="active" data-cm-vista="foto" aria-pressed="true"><i class="ti ti-photo"></i><span>Fotos</span></button>
                            <button type="button" data-cm-vista="mapa" aria-pressed="false"><i class="ti ti-flame"></i><span>Mapa de calor</span></button>
                        </div>
                        <button type="button" class="cm-cerrar d-none d-lg-inline-flex" data-bs-dismiss="modal" aria-label="Cerrar"><i class="ti ti-x"></i></button>
                    </div>
                </header>

                <div class="cm-cuerpo" id="cmCuerpo">
                    <div class="cm-panel" data-panel="fotos" id="cmPanelFotos" role="tabpanel" aria-labelledby="cmTabFotos">
                        <div class="cm-grupos" id="cmGrupos"></div>
                        <p class="cm-leyenda d-none" id="cmLeyenda">
                            <span class="cm-escala"></span>Azul: poca influencia · Rojo: zonas que más pesaron en la decisión de la red
                        </p>
                    </div>

                    <div class="cm-panel d-none" data-panel="metricas" id="cmPanelMetricas" role="tabpanel" aria-labelledby="cmTabMetricas">
                        <div class="cm-metricas" id="cmMetricas"></div>
                        <div class="cm-seccion mt-3 mb-0 d-lg-none">
                            <h6><i class="ti ti-chart-bar"></i>Probabilidad por tipo de hoja</h6>
                            <div class="cm-clases" id="cmClasesMovil"></div>
                        </div>
                    </div>

                    <div class="cm-panel d-none" data-panel="informe" id="cmPanelInforme" role="tabpanel" aria-labelledby="cmTabInforme">
                        <div class="cm-veredicto" id="cmVeredicto"><i class="ti ti-info-circle"></i><span></span></div>
                        <div class="cm-seccion ia" id="cmExplicacionBox">
                            <h6><i class="ti ti-brain"></i>Explicación de la red neuronal</h6>
                            <p id="cmExplicacion"></p>
                        </div>
                        <div class="cm-seccion" id="cmRecomendacionesBox">
                            <h6><i class="ti ti-bulb"></i>Recomendaciones</h6>
                            <ul class="cm-lista ok" id="cmRecomendaciones"></ul>
                        </div>
                        <div class="cm-seccion alerta" id="cmAdvertenciasBox">
                            <h6><i class="ti ti-alert-triangle"></i>Advertencias</h6>
                            <ul class="cm-lista" id="cmAdvertencias"></ul>
                        </div>
                        <dl class="cm-tecnico" id="cmTecnico"></dl>
                    </div>
                </div>

                <footer class="cm-pie">
                    <button type="button" class="btn btn-light cm-btn" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn cm-btn cm-btn-ok" id="cmNuevaCaptura"><i class="ti ti-camera-plus me-1"></i>Nueva captura</button>
                </footer>

                <!-- Detalle de una foto -->
                <div class="cm-visor d-none" id="cmVisor" role="dialog" aria-modal="true" aria-labelledby="cmVisorTitulo">
                    <div class="cm-visor-cab">
                        <button type="button" class="cm-cerrar" id="cmVisorVolver" aria-label="Volver a todas las fotos"><i class="ti ti-arrow-left"></i></button>
                        <h4 id="cmVisorTitulo"></h4>
                        <button type="button" class="cm-visor-nav" id="cmVisorAnterior" aria-label="Foto anterior"><i class="ti ti-chevron-left"></i></button>
                        <button type="button" class="cm-visor-nav" id="cmVisorSiguiente" aria-label="Foto siguiente"><i class="ti ti-chevron-right"></i></button>
                    </div>
                    <div class="cm-visor-cuerpo">
                        <div class="cm-visor-img" id="cmVisorImg">
                            <img id="cmVisorFoto" alt="">
                            <img id="cmVisorMapa" class="cm-img-mapa" alt="">
                            <div class="cm-vista cm-vista-flotante" id="cmVisorVista" role="group" aria-label="Ver la foto o el mapa de calor">
                                <button type="button" class="active" data-cm-vista="foto" aria-pressed="true">Foto</button>
                                <button type="button" data-cm-vista="mapa" aria-pressed="false">Mapa de calor</button>
                            </div>
                        </div>
                        <div class="cm-visor-info">
                            <span class="cm-etiqueta en-linea" id="cmVisorEtiqueta"></span>
                            <p class="cm-visor-texto" id="cmVisorTexto"></p>
                            <div class="cm-side-label ms-0">Opciones más probables</div>
                            <div class="cm-clases mb-3" id="cmVisorTop"></div>
                            <div class="cm-side-label ms-0">Calidad de esta foto</div>
                            <div class="cm-clases mb-3" id="cmVisorCalidad"></div>
                            <ul class="cm-lista cm-visor-avisos" id="cmVisorAvisos"></ul>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="{{ asset('build/assets/libs/sweetalert2/sweetalert2.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const BURST_TOTAL = 5;
    const URL_ANALIZAR = @json(route('coca-reconocimiento.analizar'));
    const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    const stage = document.getElementById('camStage');
    const video = document.getElementById('camVideo');
    const photo = document.getElementById('camPhoto');
    const scanFrame = document.getElementById('scanFrame');
    const camIdle = document.getElementById('camIdle');
    const camError = document.getElementById('camError');
    const camErrorMsg = document.getElementById('camErrorMsg');
    const camFlash = document.getElementById('camFlash');
    const camResBadge = document.getElementById('camResBadge');
    const controlsLive = document.getElementById('camControlsLive');
    const controlsPhoto = document.getElementById('camControlsPhoto');
    const camHint = document.getElementById('camHint');
    const shutterBtn = document.getElementById('shutterBtn');
    const shutterLabel = document.getElementById('shutterLabel');
    const undoBtn = document.getElementById('undoBtn');
    const burstChipText = document.getElementById('burstChipText');
    const burstDots = document.getElementById('burstDots');
    const filmstrip = document.getElementById('filmstrip');

    const resultadoPlaceholder = document.getElementById('resultadoPlaceholder');
    const resultadoContenido = document.getElementById('resultadoContenido');
    const analisisOverlay = document.getElementById('analisisOverlay');
    const analisisFotoProgreso = document.getElementById('analisisFotoProgreso');
    const nuevaCapturaBtn = document.getElementById('nuevaCapturaBtn');

    let stream = null;
    let facing = 'environment';
    let analizando = false;
    let capturas = []; // canvases de las 5 fotos

    const espera = ms => new Promise(r => setTimeout(r, ms));

    // ==================== Cámara ====================
    async function startCamera(preservarFotos = false) {
        stopCamera();
        camError.classList.add('d-none');
        camIdle.classList.add('d-none');
        photo.classList.add('d-none');
        controlsPhoto.style.display = 'none';
        if (!preservarFotos) capturas = [];

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showCamError('Tu navegador no soporta el acceso a la cámara. Usa Chrome, Edge o Firefox actualizados.');
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: facing,
                    width: { ideal: 1280 },
                    height: { ideal: 960 }
                },
                audio: false
            });

            video.srcObject = stream;
            video.classList.remove('d-none');
            video.classList.toggle('mirrored', facing === 'user');
            scanFrame.classList.remove('d-none');
            stage.classList.add('live');
            controlsLive.style.display = 'flex';
            burstDots.classList.add('visible');
            actualizarProgreso();

            video.addEventListener('loadedmetadata', () => {
                camResBadge.textContent = video.videoWidth + ' × ' + video.videoHeight;
            }, { once: true });
        } catch (err) {
            let msg = 'Revisa que el navegador tenga permiso para usar la cámara.';
            if (err.name === 'NotAllowedError') msg = 'Permiso denegado. Toca el ícono de candado en la barra del navegador y permite el acceso a la cámara.';
            if (err.name === 'NotFoundError') msg = 'No se encontró ninguna cámara en este dispositivo.';
            if (err.name === 'NotReadableError') msg = 'La cámara está siendo usada por otra aplicación. Ciérrala e intenta de nuevo.';
            showCamError(msg);
        }
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(t => t.stop());
            stream = null;
        }
        video.srcObject = null;
        video.classList.add('d-none');
        stage.classList.remove('live', 'bursting');
        controlsLive.style.display = 'none';
        camResBadge.textContent = '';
    }

    // Actualiza contador, puntos, tira y textos según las fotos tomadas
    function actualizarProgreso() {
        const n = capturas.length;

        stage.classList.toggle('has-shots', n > 0);
        burstChipText.textContent = 'Foto ' + n + ' de ' + BURST_TOTAL;
        burstDots.querySelectorAll('span').forEach((d, i) => d.classList.toggle('filled', i < n));
        shutterLabel.textContent = n >= BURST_TOTAL ? '¡Listo!' : 'Foto ' + (n + 1) + ' de ' + BURST_TOTAL;
        undoBtn.style.visibility = n > 0 ? 'visible' : 'hidden';
        shutterBtn.disabled = n >= BURST_TOTAL;
        renderFilmstrip();

        if (stream) {
            camHint.innerHTML = n === 0
                ? '<i class="ti ti-bulb me-1"></i>Toca el botón para tomar la primera foto. Cambia el ángulo entre cada toma'
                : '<i class="ti ti-camera me-1"></i>Llevas ' + n + ' de ' + BURST_TOTAL + ' fotos · faltan ' + (BURST_TOTAL - n);
        }
    }

    function showCamError(msg) {
        stopCamera();
        scanFrame.classList.add('d-none');
        camErrorMsg.textContent = msg;
        camError.classList.remove('d-none');
        camIdle.classList.add('d-none');
        controlsPhoto.style.display = 'none';
    }

    function backToIdle() {
        stopCamera();
        capturas = [];
        stage.classList.remove('has-shots');
        photo.classList.add('d-none');
        scanFrame.classList.add('d-none');
        filmstrip.classList.remove('visible');
        burstDots.classList.remove('visible');
        controlsPhoto.style.display = 'none';
        camError.classList.add('d-none');
        camIdle.classList.remove('d-none');
        camHint.innerHTML = '<i class="ti ti-bulb me-1"></i>Consejo: usa luz natural y coloca la hoja sobre un fondo claro';
    }

    document.getElementById('camStartBtn').addEventListener('click', () => startCamera());
    document.getElementById('camRetryBtn').addEventListener('click', () => startCamera());
    document.getElementById('camOffBtn').addEventListener('click', backToIdle);

    // Cambiar de cámara conserva las fotos ya tomadas
    document.getElementById('camFlipBtn').addEventListener('click', () => {
        facing = facing === 'environment' ? 'user' : 'environment';
        startCamera(true);
    });

    // ==================== Captura manual de 5 fotos ====================
    function capturarFrame() {
        const c = document.createElement('canvas');
        c.width = video.videoWidth;
        c.height = video.videoHeight;
        const ctx = c.getContext('2d');

        if (facing === 'user') {
            ctx.translate(c.width, 0);
            ctx.scale(-1, 1);
        }
        ctx.drawImage(video, 0, 0, c.width, c.height);
        c.recorteMarco = regionDelMarco(); // lo que el usuario veía dentro del marco
        return c;
    }

    // Región de la foto que se ve dentro del marco de escaneo. La cámara se
    // muestra con object-fit: cover (centrada) y el marco tiene inset: 9%.
    function regionDelMarco() {
        const vw = video.videoWidth, vh = video.videoHeight;
        const sw = stage.clientWidth, sh = stage.clientHeight;
        if (!vw || !vh || !sw || !sh) return null;

        const escala = Math.max(sw / vw, sh / vh);
        const w = (sw / escala) * 0.82;
        const h = (sh / escala) * 0.82;
        return { x: (vw - w) / 2, y: (vh - h) / 2, w: w, h: h };
    }

    function dispararFlash() {
        camFlash.classList.remove('fire');
        void camFlash.offsetWidth;
        camFlash.classList.add('fire');
    }

    // Cada toque al disparador toma UNA foto, hasta completar 5
    shutterBtn.addEventListener('click', () => {
        if (!stream || !video.videoWidth || capturas.length >= BURST_TOTAL) return;

        dispararFlash();
        stage.classList.add('bursting');
        setTimeout(() => stage.classList.remove('bursting'), 400);

        capturas.push(capturarFrame());
        actualizarProgreso();

        if (capturas.length >= BURST_TOTAL) {
            stopCamera();
            mostrarCaptura(capturas.length - 1);
            photo.classList.remove('d-none');
            controlsPhoto.style.display = 'flex';
            camHint.innerHTML = '<i class="ti ti-photo-check me-1"></i>5 fotos listas. Toca una miniatura para revisarla';
        }
    });

    // Borrar la última foto tomada
    undoBtn.addEventListener('click', () => {
        if (!capturas.length) return;
        capturas.pop();
        actualizarProgreso();
    });

    function mostrarCaptura(idx) {
        const src = capturas[idx];
        photo.width = src.width;
        photo.height = src.height;
        photo.getContext('2d').drawImage(src, 0, 0);
        filmstrip.querySelectorAll('.film-thumb').forEach((t, i) => {
            t.classList.toggle('active', i === idx);
        });
    }

    function renderFilmstrip() {
        filmstrip.innerHTML = '';
        capturas.forEach((c, idx) => {
            const img = document.createElement('img');
            img.className = 'film-thumb';
            img.src = miniatura(c, 130);
            img.alt = 'Captura ' + (idx + 1);
            img.title = 'Captura ' + (idx + 1);
            img.addEventListener('click', () => {
                if (!stream) mostrarCaptura(idx);
            });
            filmstrip.appendChild(img);
        });
        filmstrip.classList.toggle('visible', capturas.length > 0);
    }

    function miniatura(canvasSrc, ancho) {
        const c = document.createElement('canvas');
        c.width = ancho;
        c.height = Math.round(ancho * canvasSrc.height / canvasSrc.width);
        c.getContext('2d').drawImage(canvasSrc, 0, 0, c.width, c.height);
        return c.toDataURL('image/jpeg', 0.7);
    }

    document.getElementById('retakeBtn').addEventListener('click', () => startCamera());

    function nuevaCaptura() {
        resultadoContenido.classList.add('d-none');
        resultadoPlaceholder.classList.remove('d-none');
        nuevaCapturaBtn.classList.add('d-none');
        startCamera();
        stage.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    nuevaCapturaBtn.addEventListener('click', nuevaCaptura);

    // ==================== Análisis con la red neuronal (API) ====================
    const ESTADOS = {
        confirmado:     { etiqueta: 'Confirmado',        alerta: 'alert-success',   icono: 'ti-circle-check', toast: 'success' },
        probable:       { etiqueta: 'Probable',          alerta: 'alert-warning',   icono: 'ti-alert-circle', toast: 'warning' },
        no_concluyente: { etiqueta: 'No concluyente',    alerta: 'alert-secondary', icono: 'ti-help-circle',  toast: 'warning' },
        rechazado:      { etiqueta: 'No es hoja de coca', alerta: 'alert-danger',   icono: 'ti-circle-x',     toast: 'error' },
    };

    const TEXTOS_PASOS = [
        '',
        'Subiendo las fotos de forma segura…',
        'La red neuronal está clasificando la hoja…',
        'Cruzando los resultados de las fotos…',
        'Casi listo…',
    ];

    // Recorta la foto al marco, la reduce a 1024 px y la convierte a JPEG
    function fotoParaEnviar(canvasSrc) {
        const r = canvasSrc.recorteMarco || { x: 0, y: 0, w: canvasSrc.width, h: canvasSrc.height };
        const escala = Math.min(1, 1024 / Math.max(r.w, r.h));
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(r.w * escala));
        c.height = Math.max(1, Math.round(r.h * escala));
        const ctx = c.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(canvasSrc, r.x, r.y, r.w, r.h, 0, 0, c.width, c.height);

        return new Promise((resolve, reject) => {
            c.toBlob(b => b ? resolve(b) : reject(new Error('No se pudo preparar una de las fotos.')), 'image/jpeg', 0.9);
        });
    }

    // Avanza los pasos del overlay mientras el servidor responde
    function avanzarPasos(pasos, desde) {
        let i = desde;
        pasos[i].classList.add('active');
        analisisFotoProgreso.textContent = TEXTOS_PASOS[i] || '';

        const timer = setInterval(() => {
            if (i >= pasos.length - 1) return;
            pasos[i].classList.remove('active');
            pasos[i].classList.add('done');
            i++;
            pasos[i].classList.add('active');
            analisisFotoProgreso.textContent = TEXTOS_PASOS[i] || '';
        }, 1200);

        return () => {
            clearInterval(timer);
            pasos.forEach(p => { p.classList.remove('active'); p.classList.add('done'); });
        };
    }

    async function enviarAnalisis(fotos) {
        const datos = new FormData();
        fotos.forEach((blob, i) => datos.append('imagenes[]', blob, 'captura_' + (i + 1) + '.jpg'));

        const control = new AbortController();
        const limite = setTimeout(() => control.abort(), 120000);
        let respuesta;
        try {
            respuesta = await fetch(URL_ANALIZAR, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: datos,
                credentials: 'same-origin',
                signal: control.signal,
            });
        } catch (err) {
            throw new Error(err.name === 'AbortError'
                ? 'El análisis tardó demasiado. Revisa tu conexión e inténtalo de nuevo.'
                : 'No se pudo conectar con el servidor. Revisa tu conexión a internet.');
        } finally {
            clearTimeout(limite);
        }

        let json = null;
        try { json = await respuesta.json(); } catch (_) { /* respuesta sin JSON */ }

        if (respuesta.status === 419) throw new Error('Tu sesión expiró. Recarga la página para continuar.');
        if (respuesta.status === 403) throw new Error('No tienes permiso para usar el reconocimiento de coca.');
        if (!respuesta.ok || !json || !json.success) {
            throw new Error((json && json.message) || 'No se pudo analizar la hoja (error ' + respuesta.status + ').');
        }
        return json.resultado;
    }

    document.getElementById('analizarBtn').addEventListener('click', async () => {
        if (analizando || capturas.length === 0) return;
        analizando = true;

        resultadoPlaceholder.classList.add('d-none');
        resultadoContenido.classList.add('d-none');
        analisisOverlay.classList.remove('d-none');
        stage.classList.add('analyzing');

        if (window.innerWidth < 1200) {
            document.getElementById('resultadoCard').scrollIntoView({ behavior: 'smooth' });
        }

        const pasos = analisisOverlay.querySelectorAll('.analisis-step');
        pasos.forEach(p => p.classList.remove('active', 'done'));
        pasos[0].classList.add('active');
        let terminarPasos = null;

        try {
            // Paso 1: preparar cada captura (recorte al marco + JPEG)
            const fotos = [];
            for (let i = 0; i < capturas.length; i++) {
                analisisFotoProgreso.textContent = 'Preparando captura ' + (i + 1) + ' de ' + capturas.length + '…';
                fotos.push(await fotoParaEnviar(capturas[i]));
            }
            pasos[0].classList.remove('active');
            pasos[0].classList.add('done');

            // Pasos 2-5: el servidor analiza las fotos
            terminarPasos = avanzarPasos(pasos, 1);
            const resultado = await enviarAnalisis(fotos);
            terminarPasos();
            await espera(350);
            mostrarResultado(resultado, fotos);
        } catch (err) {
            if (terminarPasos) terminarPasos();
            mostrarError(err.message || 'No se pudo completar el análisis.');
        } finally {
            analisisOverlay.classList.add('d-none');
            stage.classList.remove('analyzing');
            analizando = false;
        }
    });

    function mostrarError(mensaje) {
        resultadoContenido.classList.add('d-none');
        resultadoPlaceholder.classList.remove('d-none');

        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'No se pudo analizar', text: mensaje, confirmButtonText: 'Entendido' });
        } else if (typeof window.adepToast === 'function') {
            window.adepToast('error', 'No se pudo analizar', mensaje);
        } else {
            alert(mensaje);
        }
    }

    // ==================== Modal «Análisis completo» ====================
    const ESTADO_CHIP = { confirmado: 'Confirmado', probable: 'Probable', no_concluyente: 'No concluyente', rechazado: 'Rechazado' };
    const MARCA = {
        confirmado: ['ti-leaf', ''], probable: ['ti-leaf', 't-alerta'],
        no_concluyente: ['ti-help-circle', 't-neutro'], rechazado: ['ti-ban', 't-mal'],
    };
    const SECCIONES = { fotos: 'Fotos analizadas', metricas: 'Métricas del análisis', informe: 'Informe de la red neuronal' };
    const METRICAS = [
        ['confianza_modelo', 'Confianza de la red neuronal', 'ti-brain', 'Qué tan segura está la red del resultado.'],
        ['consistencia', 'Consistencia entre fotos', 'ti-arrows-diff', 'Qué parte de las fotos señala el mismo resultado.'],
        ['similitud_dataset', 'Similitud con el entrenamiento', 'ti-leaf', 'Cuánto se parecen tus fotos a las hojas con las que aprendió la red.'],
        ['iluminacion', 'Iluminación', 'ti-sun', 'Luz de las fotos: ni muy oscuras ni quemadas.'],
        ['nitidez', 'Nitidez', 'ti-focus-2', 'Enfoque de las fotos: sin movimiento ni desenfoque.'],
    ];

    const modalEl = document.getElementById('cocaResultadoModal');
    document.body.appendChild(modalEl); // directo en <body>: el modal no queda atrapado en las capas de la página
    const visor = document.getElementById('cmVisor');
    let resultadoActual = null;
    let urlsFotos = [];      // fotos enviadas a la red (recortadas al marco)
    let vista = 'foto';      // 'foto' o 'mapa' (mapa de calor)
    let visorIdx = -1;
    let verTodasClases = false;
    let iniciarTrasCerrar = false;

    const pct = v => Math.round(Math.min(100, Math.max(0, Number(v) || 0)));
    const pctTexto = v => { const n = Math.min(100, Math.max(0, Number(v) || 0)); return (n > 0 && n < 10 ? n.toFixed(1) : Math.round(n)) + '%'; };
    const nivel = v => v >= 70 ? ['ok', 'Alta'] : (v >= 40 ? ['alerta', 'Media'] : ['mal', 'Baja']);
    const mapaValido = m => (typeof m === 'string' && m.startsWith('data:image/jpeg;base64,')) ? m : null;
    const urlFoto = indice => urlsFotos[indice - 1] || null;

    // Crea un elemento; el texto siempre va como texto, nunca como HTML
    function el(etiqueta, clase, texto) {
        const n = document.createElement(etiqueta);
        if (clase) n.className = clase;
        if (texto !== undefined && texto !== null) n.textContent = texto;
        return n;
    }

    const icono = nombre => el('i', 'ti ' + nombre);

    // Barra de segmentos [[valor, tono]]; el ancho se anima al mostrarse
    function barra(segmentos) {
        const b = el('div', 'cm-barra');
        segmentos.forEach(([valor, tono]) => {
            const s = el('span', 't-' + tono);
            s.dataset.w = Math.min(100, Math.max(0, Number(valor) || 0));
            b.appendChild(s);
        });
        return b;
    }

    function animarBarras(raiz) {
        const barras = raiz.querySelectorAll('.cm-barra > span');
        barras.forEach(s => { s.style.width = '0'; });
        requestAnimationFrame(() => requestAnimationFrame(() => {
            barras.forEach(s => { s.style.width = s.dataset.w + '%'; });
        }));
    }

    // Filas «nombre · % · barra» (probabilidad por tipo, top 3 y calidad de una foto)
    function filas(contenedor, datos) {
        contenedor.replaceChildren(...datos.map(([nombre, valor, tono, ganadora]) => {
            const fila = el('div', 'cm-fila' + (ganadora ? ' es-ganadora' : ''));
            const top = el('div', 'cm-fila-top');
            top.append(el('span', null, nombre), el('b', null, pctTexto(valor)));
            fila.append(top, barra([[valor, tono]]));
            return fila;
        }));
    }

    function pintarLista(idLista, idCaja, items, claseIcono) {
        const lista = document.getElementById(idLista);
        lista.replaceChildren(...(Array.isArray(items) ? items : []).map(texto => {
            const li = el('li');
            li.append(icono(claseIcono), el('span', null, String(texto)));
            return li;
        }));
        document.getElementById(idCaja).classList.toggle('d-none', lista.children.length === 0);
    }

    // Verde = coincide con el resultado; rojo = no es coca o no coincide; ámbar = parece coca en un análisis rechazado
    function etiqueta(span, f, rechazado, extra) {
        const [clase, ico] = !f.es_hoja_de_coca ? ['es-mal', 'ti-ban']
            : (rechazado ? ['es-duda', 'ti-help'] : (f.coincide ? ['', 'ti-check'] : ['es-mal', 'ti-ban']));
        span.className = ['cm-etiqueta', clase, extra].filter(Boolean).join(' ');
        span.replaceChildren(el('span', null, f.clase || '—'), icono(ico));
    }

    function crearTile(f, rechazado, orden, posicion) {
        const boton = el('button', 'cm-tile');
        boton.type = 'button';
        boton.style.setProperty('--i', orden);
        boton.dataset.indice = f.indice;
        boton.setAttribute('aria-label', 'Foto ' + f.indice + ': ' + f.clase + ', ' + pct(f.confianza) + '%. Ver detalle');

        const foto = urlFoto(f.indice), mapa = mapaValido(f.mapa_atencion);
        if (foto || mapa) {
            const img = el('img', foto && mapa ? 'cm-img-foto' : 'cm-img-unica');
            img.src = foto || mapa;
            img.alt = '';
            boton.appendChild(img);
            if (foto && mapa) {
                const imgMapa = el('img', 'cm-img-mapa');
                imgMapa.src = mapa;
                imgMapa.alt = '';
                boton.appendChild(imgMapa);
            }
        } else {
            const vacio = el('span', 'cm-sin-foto');
            vacio.appendChild(icono('ti-photo-off'));
            boton.appendChild(vacio);
        }

        const chip = el('span', 'cm-chip izq' + (f.fuera_de_distribucion ? ' ajena' : ''));
        if (f.fuera_de_distribucion) chip.append(icono('ti-alert-triangle'), 'Foto ' + f.indice + ' · ajena');
        else chip.textContent = 'Foto ' + f.indice;
        const badge = el('span');
        etiqueta(badge, f, rechazado);
        boton.append(chip, el('span', 'cm-chip der', pct(f.confianza) + '%'), badge);
        boton.addEventListener('click', () => abrirVisor(posicion));
        return boton;
    }

    function pintarModal(res, horaTexto) {
        resultadoActual = res;
        const clave = ESTADOS[res.estado] ? res.estado : 'no_concluyente';
        const rechazado = clave === 'rechazado';
        const fotos = Array.isArray(res.por_imagen) ? res.por_imagen : [];
        const total = fotos.length;
        const coinciden = fotos.filter(f => f.coincide), resto = fotos.filter(f => !f.coincide);
        const pctA = total ? Math.round(coinciden.length / total * 100) : 0;
        const pctB = total ? 100 - pctA : 0;
        const titulo = res.tipo || (rechazado ? 'No es hoja de coca' : 'No identificado');

        // Lateral: resultado, «Todas las fotos», probabilidad por tipo y resumen
        document.getElementById('cmTitulo').textContent = titulo;
        const chip = document.getElementById('cmEstado');
        chip.textContent = ESTADO_CHIP[clave];
        chip.className = 'cm-estado is-' + clave;
        document.getElementById('cmConfianza').textContent = pct(res.confianza) + '% de confianza';
        const marca = document.getElementById('cmMarcaIco');
        marca.className = 'cm-marca-ico ' + MARCA[clave][1];
        marca.replaceChildren(icono(MARCA[clave][0]));
        document.getElementById('cmNavFotos').textContent = total;

        const top = el('div', 'cm-fila-top');
        top.append(el('span', null, 'Todas las fotos'), el('b', null, pctA + '%'));
        document.getElementById('cmGeneral').replaceChildren(top,
            barra([[pctA, rechazado ? 'mal' : 'ok'], [pctB, rechazado ? 'alerta' : 'mal']]));
        pintarClases();

        const resumen = document.getElementById('cmResumen');
        resumen.replaceChildren();
        if (total && rechazado) {
            resumen.append(el('b', 'mal', pctA + '%'), ' de tus fotos no muestran una hoja de coca');
            if (resto.length) resumen.append('; ', el('b', 'alerta', pctB + '%'), ' sí se parecen a una');
            resumen.append('.');
        } else if (total) {
            resumen.append(el('b', 'ok', pctA + '%'), ' de tus fotos coinciden con «' + titulo + '»');
            if (resto.length) resumen.append(', ', el('b', 'mal', pctB + '%'), ' no');
            resumen.append('.');
        }
        const ajenas = fotos.filter(f => f.fuera_de_distribucion).length;
        if (ajenas) {
            resumen.append(' ', el('b', 'alerta', ajenas + (ajenas === 1 ? ' foto' : ' fotos')),
                ajenas === 1 ? ' no se parece a las del entrenamiento.' : ' no se parecen a las del entrenamiento.');
        }

        document.getElementById('cmHora').textContent = [horaTexto, total + (total === 1 ? ' foto' : ' fotos'),
            res.tiempo_proceso ? Number(res.tiempo_proceso).toFixed(1) + ' s de análisis' : ''].filter(Boolean).join(' · ');

        // Fotos agrupadas: «Coinciden / No coinciden» (o «Sin hoja de coca / Parecen hoja de coca»)
        const grupos = document.getElementById('cmGrupos');
        grupos.replaceChildren();
        const nombres = rechazado ? ['Sin hoja de coca', 'Parecen hoja de coca'] : ['Coinciden', 'No coinciden'];
        let orden = 0;
        [[coinciden, nombres[0], pctA], [resto, nombres[1], pctB]].forEach(([lista, nombre, porcentaje]) => {
            if (!lista.length) return;
            const h = el('h4', 'cm-grupo-titulo', nombre);
            h.append(el('small', null, porcentaje + '%'));
            const grid = el('div', 'cm-grid');
            lista.forEach(f => grid.appendChild(crearTile(f, rechazado, orden++, fotos.indexOf(f))));
            const grupo = el('section', 'cm-grupo');
            grupo.append(h, grid);
            grupos.appendChild(grupo);
        });
        if (!total) grupos.appendChild(el('p', 'cm-vacio', 'La API no devolvió el detalle de cada foto.'));
        modalEl.dataset.hayMapas = fotos.some(f => mapaValido(f.mapa_atencion) && urlFoto(f.indice)) ? '1' : '';
        cambiarVista('foto');

        // Métricas (0-100) con nivel alto / medio / bajo
        const m = res.metricas || {};
        document.getElementById('cmMetricas').replaceChildren(...METRICAS
            .filter(([k]) => m[k] !== null && m[k] !== undefined)
            .map(([k, nombre, ico, ayuda], i) => {
                const valor = pct(m[k]);
                const [tono, texto] = nivel(valor);
                const tarjeta = el('div', 'cm-metrica');
                tarjeta.style.setProperty('--i', i);
                const caja = el('span', 'cm-metrica-ico t-' + tono);
                caja.appendChild(icono(ico));
                const textos = el('div', 'cm-min0');
                textos.append(el('div', 'cm-metrica-nombre', nombre), el('div', 'cm-metrica-nivel cm-tono-' + tono, texto));
                const cab = el('div', 'cm-metrica-top');
                cab.append(caja, textos, el('div', 'cm-metrica-valor', valor + '%'));
                tarjeta.append(cab, barra([[valor, tono]]), el('p', null, ayuda));
                return tarjeta;
            }));

        // Informe
        document.getElementById('cmVeredicto').className = 'cm-veredicto is-' + clave;
        document.getElementById('cmVeredicto').replaceChildren(icono(ESTADOS[clave].icono), el('span', null, res.mensaje || ''));
        document.getElementById('cmExplicacion').textContent = res.explicacion || '';
        document.getElementById('cmExplicacionBox').classList.toggle('d-none', !res.explicacion);
        pintarLista('cmRecomendaciones', 'cmRecomendacionesBox', res.recomendaciones, 'ti-circle-check');
        pintarLista('cmAdvertencias', 'cmAdvertenciasBox', res.advertencias, 'ti-alert-triangle');
        const modelo = res.modelo && res.modelo.arquitectura
            ? res.modelo.arquitectura + (res.modelo.imgsz ? ' · ' + res.modelo.imgsz + ' px' : '') : 'Red neuronal convolucional';
        document.getElementById('cmTecnico').replaceChildren(...[
            ['Fotos analizadas', String(res.imagenes_analizadas || total)],
            ['Tiempo de análisis', res.tiempo_proceso ? Number(res.tiempo_proceso).toFixed(1) + ' s' : '—'],
            ['Hora', horaTexto],
            ['Red neuronal', modelo],
        ].map(([k, v]) => {
            const d = el('div');
            d.append(el('dt', null, k), el('dd', null, v));
            return d;
        }));
    }

    // Probabilidad por tipo: 5 en el lateral (con «ver todos») y todas en Métricas (celular)
    function pintarClases() {
        const tonoGanadora = resultadoActual.estado === 'rechazado' ? 'mal' : 'ok';
        const datos = Object.entries(resultadoActual.probabilidades || {})
            .map(([n, v]) => [n, Number(v) || 0])
            .sort((a, b) => b[1] - a[1])
            .map(([n, v], i) => [n, v, i === 0 ? tonoGanadora : 'suave', i === 0]);
        filas(document.getElementById('cmClases'), verTodasClases ? datos : datos.slice(0, 5));
        filas(document.getElementById('cmClasesMovil'), datos);
        const boton = document.getElementById('cmVerMas');
        boton.classList.toggle('d-none', datos.length <= 5);
        boton.textContent = verTodasClases ? 'Ver menos' : 'Ver los ' + datos.length + ' tipos';
    }

    document.getElementById('cmVerMas').addEventListener('click', () => {
        verTodasClases = !verTodasClases;
        pintarClases();
        animarBarras(document.getElementById('cmClases'));
        animarBarras(document.getElementById('cmClasesMovil'));
    });

    function cambiarVista(nueva) {
        vista = nueva === 'mapa' ? 'mapa' : 'foto';
        document.getElementById('cmGrupos').classList.toggle('ver-mapa', vista === 'mapa');
        document.getElementById('cmLeyenda').classList.toggle('d-none', vista !== 'mapa');
        modalEl.querySelectorAll('[data-cm-vista]').forEach(b => {
            const activo = b.dataset.cmVista === vista;
            b.classList.toggle('active', activo);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
        if (visorIdx >= 0) pintarVisor();
    }

    modalEl.querySelectorAll('[data-cm-vista]').forEach(b => b.addEventListener('click', () => cambiarVista(b.dataset.cmVista)));

    // Pestañas: Fotos / Métricas / Informe (también con las flechas del teclado)
    function mostrarSeccion(nombre) {
        modalEl.querySelectorAll('[data-cm-tab]').forEach(b => {
            const activa = b.dataset.cmTab === nombre;
            b.classList.toggle('active', activa);
            b.setAttribute('aria-selected', activa ? 'true' : 'false');
            b.tabIndex = activa ? 0 : -1;
        });
        modalEl.querySelectorAll('.cm-panel').forEach(p => p.classList.toggle('d-none', p.dataset.panel !== nombre));
        document.getElementById('cmSeccion').textContent = SECCIONES[nombre];
        document.getElementById('cmVistaControl').classList.toggle('d-none', nombre !== 'fotos' || !modalEl.dataset.hayMapas);
        document.getElementById('cmCuerpo').scrollTop = 0;
        const app = modalEl.querySelector('.cm-app'), nav = modalEl.querySelector('.cm-nav');
        if (app.scrollTop > nav.offsetTop) app.scrollTop = nav.offsetTop; // celular: la sección empieza arriba
        animarBarras(modalEl.querySelector('.cm-panel[data-panel="' + nombre + '"]'));
    }

    const pestanas = [...modalEl.querySelectorAll('[data-cm-tab]')];
    pestanas.forEach((b, i) => {
        b.addEventListener('click', () => mostrarSeccion(b.dataset.cmTab));
        b.addEventListener('keydown', e => {
            const paso = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
            if (!paso) return;
            e.preventDefault();
            const otra = pestanas[(i + paso + pestanas.length) % pestanas.length];
            otra.focus();
            mostrarSeccion(otra.dataset.cmTab);
        });
    });

    // Detalle de una foto: imagen grande, sus 3 opciones más probables y su calidad
    function abrirVisor(posicion) {
        if (!resultadoActual || !(resultadoActual.por_imagen || [])[posicion]) return;
        visorIdx = posicion;
        pintarVisor();
        visor.classList.remove('d-none');
        document.getElementById('cmVisorVolver').focus();
    }

    function cerrarVisor() {
        if (visorIdx < 0) return;
        const indice = Number(resultadoActual.por_imagen[visorIdx].indice);
        visor.classList.add('d-none');
        visorIdx = -1;
        const tile = modalEl.querySelector('.cm-tile[data-indice="' + indice + '"]');
        if (tile) tile.focus();
    }

    function moverVisor(paso) {
        const nuevo = visorIdx + paso;
        if (nuevo >= 0 && nuevo < resultadoActual.por_imagen.length) {
            visorIdx = nuevo;
            pintarVisor();
        }
    }

    function pintarVisor() {
        const fotos = resultadoActual.por_imagen;
        const f = fotos[visorIdx];
        const foto = urlFoto(f.indice), mapa = mapaValido(f.mapa_atencion);
        const fijar = (img, src) => {
            if (src) img.src = src; else img.removeAttribute('src');
            img.classList.toggle('d-none', !src);
        };

        document.getElementById('cmVisorTitulo').textContent = 'Foto ' + f.indice + ' de ' + fotos.length;
        const imgFoto = document.getElementById('cmVisorFoto');
        fijar(imgFoto, foto || mapa);
        imgFoto.alt = 'Foto ' + f.indice;
        fijar(document.getElementById('cmVisorMapa'), foto && mapa ? mapa : null);
        document.getElementById('cmVisorVista').classList.toggle('d-none', !(foto && mapa));
        document.getElementById('cmVisorImg').classList.toggle('ver-mapa', vista === 'mapa' && !!(foto && mapa));

        etiqueta(document.getElementById('cmVisorEtiqueta'), f, resultadoActual.estado === 'rechazado', 'en-linea');
        document.getElementById('cmVisorTexto').textContent = pctTexto(f.confianza) + ' de confianza en esta foto. ' +
            (f.coincide ? 'Coincide con el resultado final.' : 'No coincide con el resultado final («' + (resultadoActual.tipo || '') + '»).');
        filas(document.getElementById('cmVisorTop'), (Array.isArray(f.top) ? f.top : []).map((t, i) =>
            [String(t.nombre || ''), Number(t.probabilidad) || 0, i === 0 ? (f.es_hoja_de_coca ? 'ok' : 'mal') : 'suave', i === 0]));
        filas(document.getElementById('cmVisorCalidad'), [['Iluminación', f.iluminacion], ['Nitidez', f.nitidez],
            ['Similitud con el entrenamiento', f.similitud]]
            .filter(([, v]) => v !== null && v !== undefined)
            .map(([n, v]) => [n, v, nivel(pct(v))[0], false]));

        const avisos = (f.fuera_de_distribucion ? ['No se parece a las fotos del entrenamiento (otro objeto, fondo o luz muy distinta).'] : [])
            .concat(Array.isArray(f.advertencias) ? f.advertencias : []);
        document.getElementById('cmVisorAvisos').replaceChildren(...avisos.map(t => {
            const li = el('li');
            li.append(icono('ti-alert-triangle'), el('span', null, String(t)));
            return li;
        }));
        document.getElementById('cmVisorAnterior').disabled = visorIdx === 0;
        document.getElementById('cmVisorSiguiente').disabled = visorIdx === fotos.length - 1;
        animarBarras(visor);
    }

    document.getElementById('cmVisorVolver').addEventListener('click', cerrarVisor);
    document.getElementById('cmVisorAnterior').addEventListener('click', () => moverVisor(-1));
    document.getElementById('cmVisorSiguiente').addEventListener('click', () => moverVisor(1));

    // Con el detalle abierto: Esc vuelve a las fotos (sin cerrar el modal) y las flechas cambian de foto
    modalEl.addEventListener('keydown', e => {
        if (visorIdx < 0) return;
        const accion = { Escape: cerrarVisor, ArrowLeft: () => moverVisor(-1), ArrowRight: () => moverVisor(1) }[e.key];
        if (!accion) return;
        e.preventDefault();
        e.stopPropagation();
        accion();
    }, true);

    modalEl.addEventListener('show.bs.modal', () => document.body.classList.add('coca-modal-abierto'));
    modalEl.addEventListener('shown.bs.modal', () => animarBarras(modalEl));
    modalEl.addEventListener('hidden.bs.modal', () => {
        document.body.classList.remove('coca-modal-abierto');
        visor.classList.add('d-none');
        visorIdx = -1;
        if (iniciarTrasCerrar) {
            iniciarTrasCerrar = false;
            nuevaCaptura();
        }
    });

    function abrirModal() {
        if (!resultadoActual || !window.bootstrap || !window.bootstrap.Modal) return false;
        mostrarSeccion('fotos');
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        return true;
    }

    document.getElementById('verAnalisisBtn').addEventListener('click', abrirModal);
    document.getElementById('cmNuevaCaptura').addEventListener('click', () => {
        iniciarTrasCerrar = true;
        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    });

    // Resumen en la tarjeta de la página y análisis completo en el modal
    function mostrarResultado(res, fotos) {
        const clave = ESTADOS[res.estado] ? res.estado : 'no_concluyente';
        const estado = ESTADOS[clave];
        const confianza = pct(res.confianza);
        const horaTexto = new Date().toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });

        resultadoPlaceholder.classList.add('d-none');
        resultadoContenido.classList.remove('d-none', 'fade-up');
        void resultadoContenido.offsetWidth;
        resultadoContenido.classList.add('fade-up');
        nuevaCapturaBtn.classList.remove('d-none');

        document.getElementById('resTipo').textContent = clave === 'rechazado' ? 'Hoja no reconocida' : (res.tipo || 'No identificado');
        const badge = document.getElementById('resCalidad');
        badge.textContent = estado.etiqueta;
        badge.className = 'badge fw-bold px-3 py-2 rounded-pill shadow-sm estado-badge estado-' + clave;
        document.getElementById('resHora').textContent = horaTexto +
            (res.tiempo_proceso ? ' · ' + Number(res.tiempo_proceso).toFixed(1) + ' s' : '');
        const total = res.imagenes_analizadas || capturas.length;
        document.getElementById('resCapturas').textContent = total + (total === 1 ? ' captura analizada' : ' capturas analizadas');

        document.getElementById('resStack').replaceChildren(...capturas.map((c, idx) => {
            const img = el('img');
            img.src = miniatura(c, 92);
            img.alt = 'Captura ' + (idx + 1);
            return img;
        }));

        // Anillo y contador de confianza animados
        const ring = document.getElementById('confRing');
        const circunferencia = 339.3;
        ring.style.strokeDashoffset = circunferencia;
        setTimeout(() => { ring.style.strokeDashoffset = circunferencia * (1 - confianza / 100); }, 80);
        const confEl = document.getElementById('resConfianza');
        let actual = 0;
        confEl.textContent = '0%';
        if (confianza > 0) {
            const intervalo = setInterval(() => {
                actual += Math.max(1, Math.round(confianza / 30));
                if (actual >= confianza) { actual = confianza; clearInterval(intervalo); }
                confEl.textContent = actual + '%';
            }, 40);
        }

        const alerta = document.getElementById('resMensaje');
        alerta.className = 'alert border-0 shadow-sm d-flex align-items-start gap-2 mb-3 fs-12 ' + estado.alerta;
        alerta.querySelector('i').className = 'ti ' + estado.icono + ' fs-16 mt-1 flex-shrink-0';
        alerta.querySelector('span').textContent = res.mensaje || '';

        urlsFotos.forEach(u => URL.revokeObjectURL(u));
        urlsFotos = (fotos || []).map(b => URL.createObjectURL(b));
        verTodasClases = false;
        pintarModal(res, horaTexto);
        if (!abrirModal() && typeof window.adepToast === 'function') {
            window.adepToast(estado.toast, clave === 'confirmado' ? 'Análisis completado' : estado.etiqueta, res.mensaje || '');
        }
    }

    // Apagar la cámara al salir de la página
    window.addEventListener('beforeunload', stopCamera);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && stream) backToIdle();
    });
});
</script>
@endsection
