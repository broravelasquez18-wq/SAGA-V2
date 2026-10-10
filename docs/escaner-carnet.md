# Escaneo de carnet del instructor

Disponible en Nueva Ocupación del dashboard, Ocupaciones y Calendario del celador.
Abrir Nueva Ocupación muestra primero el escáner y solicita acceso a la cámara.
El formulario permanece oculto y deshabilitado hasta reconocer un instructor o
elegir «Registrar manualmente». Una lectura desconocida mantiene el paso de escaneo.
Al continuar, se libera la cámara y se conservan la fecha del calendario y los
demás datos introducidos. «Volver a escanear» permite cambiar de instructor.
Cerrar y abrir una nueva ocupación siempre vuelve al paso inicial.
En «Otras opciones de escaneo» se puede elegir la cámara y leer una foto JPG,
PNG o WebP de hasta 16 MB desde «Leer foto del carnet».

El código debe contener únicamente la cédula (se permiten espacios exteriores).
La coincidencia es exacta y conserva ceros iniciales. Se aceptan instructores
activos de la sede y contratistas, conforme a RegistrarOcupacion.php. Una lectura
desconocida, inválida o duplicada no selecciona a nadie y elimina la selección
anterior. El escaneo no envía el formulario automáticamente.

## Cámara y despliegue

En el celular se requiere HTTPS con certificado válido y permiso de cámara.
Una dirección HTTP de la red local no habilita la cámara. Se prefiere la cámara
trasera; cerrar el lector, el formulario o pasar la página a segundo plano libera
la cámara. La sesión de lectura termina tras 60 segundos sin resultados.
Se solicita resolución de 1920 × 1080 (el dispositivo puede entregar menos),
enfoque continuo cuando está disponible. El lector se especializa en Code 128,
el formato verificado en el carnet suministrado. Procesa contraste local gaussiano,
varias líneas y recortes, y prueba códigos horizontales y verticales. Comprueba
el checksum y exige coincidencia en dos líneas del mismo fotograma, que se acepta
sin esperar otro. Primero busca con umbral simple desde el centro; solo usa
contraste adaptativo si falla la vía rápida. Entre intentos espera 16 ms, sin
acumular fotogramas. No descarta ceros iniciales ni inventa dígitos.
El procesamiento se ejecuta en un Web Worker local, con una sola imagen pendiente,
para mantener el formulario interactivo. Cerrar el lector cancela el trabajo.
En una webcam, mantener el código horizontal y completo, con sus márgenes
blancos visibles. Alejar el carnet si las barras se ven borrosas: algunas cámaras
de foco fijo no enfocan objetos cercanos.
Las imágenes se procesan localmente, sin subirlas al servidor.

Biblioteca: @zxing/library 0.21.3, distribuida localmente en assets/js/lib,
con su licencia Apache-2.0. Fuente: https://github.com/zxing-js/library
Distribución: https://unpkg.com/@zxing/library@0.21.3/umd/index.min.js
No guardar bibliotecas públicas en una ruta /vendor/: .htaccess la bloquea.

## Verificación

Ejecutar `node tests/escaner_carnet.cjs` y comprobar la sintaxis PHP de las tres
vistas y del parcial _escaner_carnet.php.

Con Node, Playwright (Chrome) y sharp disponibles, ejecutar:
`node tests/carnet_browser.cjs <imagen> <cedula-esperada>`.
Comprueba la imagen y sus rotaciones con el decodificador real, la lectura de
foto en Chrome, el vídeo con una fuente reproducible, la selección, un carnet
sin coincidencia y la cancelación mientras se espera permiso. Las imágenes
personales se leen desde su ruta original y no se incorporan al repositorio.

Prueba pendiente en dispositivo físico: abrir por HTTPS en Android/iPhone,
leer un carnet real, verificar nombre, registrar una ocupación de prueba y
comprobarla. Verificar también permiso denegado, carnet desconocido, cancelación
y cierre del formulario mientras se solicita el permiso.
