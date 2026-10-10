<div class="form-grupo carnet-escaner" id="escanerCarnet">
    <h3>Identifica al instructor</h3>
    <p>Escanea el código de barras del carnet para continuar con la ocupación.</p>
    <button type="button" class="carnet-boton" id="iniciarEscaneo"><i class="bi bi-upc-scan" aria-hidden="true"></i> Escanear carnet con la cámara</button>
    <div id="panelCamara" hidden>
        <p>Coloca el código horizontal en el centro, con los márgenes blancos visibles y sin reflejos. Si se ve borroso, aleja el carnet lentamente.</p>
        <div class="carnet-preview">
            <video id="videoCarnet" muted playsinline aria-label="Cámara para leer el carnet"></video>
            <div class="carnet-guia" aria-hidden="true"></div>
        </div>
        <button type="button" class="carnet-boton" id="detenerEscaneo">Cerrar cámara</button>
    </div>
    <p id="estadoCarnet" role="status" aria-live="polite">Escanea el carnet para continuar o elige Registrar manualmente.</p>
    <button type="button" class="carnet-manual" id="registroManual">Registrar manualmente</button>
    <details class="carnet-opciones">
        <summary>Otras opciones de escaneo</summary>
        <button type="button" class="carnet-boton" id="elegirFotoCarnet">Leer foto del carnet</button>
        <input type="file" id="fotoCarnet" accept="image/jpeg,image/png,image/webp" hidden>
        <label for="camaraCarnet">Cámara</label>
        <select id="camaraCarnet"><option value="">Cámara automática</option></select>
    </details>
</div>
