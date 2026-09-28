{{--
    CANAL QR — YAPE / PLIN

    Por qué existe: Mercado Pago Perú no expone una API de QR presencial. Yape
    dentro de Checkout Pro funciona, pero cobra comisión y obliga a pasar por la
    pasarela. Este canal es para quien prefiere transferir directo, y para
    ferias y afiches donde solo hay un QR impreso.

    TODO ESTO SE RENDERIZA EN EL SERVIDOR. Sin JavaScript el bloque se ve
    entero y se puede rellenar; lo que el JS añade es el despliegue al elegir
    medio, la vista previa del archivo y el envío sin recarga.

    El aviso del final no es cortesía: es lo único honesto que se puede decir.
    Aquí nadie confirma un pago — lo confirma una persona del equipo después de
    mirar la captura.
--}}

@php($qrImagen = trim((string) config('donaciones.qr_imagen')))

{{-- NACE VISIBLE. Lo oculta `qr.js` al arrancar y lo despliega el selector
     de medio de pago. Si naciera oculto, quien no tenga JavaScript no vería
     nunca este canal. --}}
<div class="don-qr" data-qr-bloque>

    @if ($qrImagen === '')
        {{-- Sin imagen configurada no se puede pedir que nadie transfiera: no
             hay a dónde. Mejor decirlo que enseñar un hueco roto. --}}
        <div class="don-aviso don-aviso--atencion" role="status">
            <i data-lucide="alert-circle"></i>
            <span>El pago con Yape y Plin no está disponible ahora mismo. Puedes donar con tarjeta.</span>
        </div>
    @else
        <ol class="don-qr__pasos">
            <li>
                <strong>1. Escanea el código con tu app</strong>
                <span>Yape o Plin, desde otro teléfono o con la cámara.</span>

                <img class="don-qr__imagen"
                     src="{{ $qrImagen }}"
                     alt="Código QR de Yape y Plin de la Fundación Territorial Comunitarios"
                     width="300" height="300"
                     loading="lazy">
            </li>

            <li>
                <strong>2. Transfiere exactamente este monto</strong>
                <span>Si transfieres otra cantidad, registramos la que llegó de verdad.</span>

                <div class="don-qr__monto">
                    {{-- Lo actualiza `qr.js` en cuanto se escribe el monto arriba.
                         Sin JavaScript se queda en cero y el donante ve el importe
                         que él mismo escribió en el campo de arriba. --}}
                    <strong data-qr-monto>{{ $simboloMoneda }} 0.00</strong>
                    <button type="button" class="button button-ghost" data-qr-copiar
                            data-copiado="Copiado">
                        <i data-lucide="copy"></i> Copiar
                    </button>
                </div>
            </li>

            <li>
                <strong>3. Dinos con cuál pagaste</strong>

                <div class="don-qr__medios">
                    <label class="don-monto">
                        <input type="radio" name="proveedor_pago" value="yape_qr" checked>
                        Yape
                    </label>
                    <label class="don-monto">
                        <input type="radio" name="proveedor_pago" value="plin_qr">
                        Plin
                    </label>
                </div>

                <div class="don-campo" data-campo="referencia_pago">
                    <label for="qr-referencia">Número de operación <span class="don-campo__ayuda">(opcional)</span></label>
                    <span class="don-campo__ayuda" id="ayuda-referencia">
                        El que te muestra la app al terminar. Nos ayuda a encontrar tu pago más rápido.
                    </span>
                    <input type="text" id="qr-referencia" name="referencia_pago"
                           maxlength="100" placeholder="000123456"
                           aria-describedby="ayuda-referencia error-referencia_pago">
                    <strong class="don-campo__error" id="error-referencia_pago" data-campo-error></strong>
                </div>
            </li>

            <li>
                <strong>4. Adjunta la constancia</strong>
                <span>La captura de la app o el PDF del banco. Máximo {{ (int) config('donaciones.comprobante.max_mb', 10) }} MB.</span>

                <div class="don-campo" data-campo="comprobante">
                    <label class="don-qr__comprobante" for="qr-comprobante">
                        <i data-lucide="upload" aria-hidden="true"></i>
                        <span data-qr-nombre>Elegir imagen o PDF</span>
                        <input type="file" id="qr-comprobante" name="comprobante"
                               accept="image/jpeg,image/png,image/webp,application/pdf"
                               aria-describedby="error-comprobante">
                    </label>

                    {{-- Vista previa ANTES de enviar: es la forma de darse cuenta
                         de que se adjuntó la captura equivocada, que pasa mucho. --}}
                    <figure class="don-qr__previa" data-qr-previa hidden>
                        <img src="" alt="Vista previa del comprobante que vas a enviar" data-qr-previa-imagen>
                        <figcaption data-qr-previa-texto></figcaption>
                    </figure>

                    <strong class="don-campo__error" id="error-comprobante" data-campo-error></strong>
                </div>
            </li>
        </ol>

        <p class="don-qr__aviso">
            <i data-lucide="clock" aria-hidden="true"></i>
            <span>
                Tu aporte aparecerá en el contador cuando nuestro equipo verifique el
                comprobante. Suele tomar menos de 24 horas.
            </span>
        </p>

        <button type="button"
                class="button button-coral"
                data-qr-enviar
                data-texto-original="Enviar mi comprobante">
            Enviar mi comprobante <i data-lucide="upload"></i>
        </button>
    @endif
</div>
