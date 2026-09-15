# Entregables, proceso y reuniones — plan de implementación

Cómo Breakfast define la marca de un cliente entregable por entregable, la
lleva por sus tres pasos, le entrega sus archivos, agenda reuniones que avisan
solas, y le da al asistente una base de datos que puede leer entera.

Decisiones tomadas antes de escribir esto:

- **Los 48 entregables reemplazan a los 42 campos.** No conviven. La fuente es
  `Entregables Breakfast.pdf`, que es lo que el equipo realmente produce.
- **Un entregable es un texto. Siempre.** Prosa, hex, o links. Una sola forma.
- **Sin estado.** Lleno es completo, vacío es pendiente.
- **Los tres pasos no se cruzan con los entregables.** Ningún entregable
  pertenece a un paso; cualquiera puede salir en cualquiera.
- **Los tiers de servicio (A–E) no se guardan.** Sirvieron para deducir qué es
  obligatorio; después de eso no existen en el modelo.

---

## 1. La idea de fondo

**`brand_deliverables` es la base de datos del asistente.**

No es un tablero de trabajo del que el modelo lee un pedazo. Es lo que el
modelo lee, entero. De ahí salen las tres reglas:

**Todo entregable es texto.** Un archivo nunca es el entregable. Cuando el
entregable *es* un asset —el identificativo principal, las ilustraciones— el
texto es el **link** al archivo subido, y si son varios, son varios links.
Así no hay entregables opacos: no existe una fila que el modelo no pueda leer.

**No hay columna de estado.** Si el campo tiene algo, el entregable está
hecho. Si está vacío, falta. Un estado aparte sólo agrega una segunda verdad
que alguien tiene que acordarse de mover, y que puede contradecir al
contenido. Es la misma decisión que en `users.permissions`, donde no existe un
nivel "ninguno" — la ausencia es la representación.

**La ausencia se declara, nunca se omite.** Un entregable obligatorio vacío se
imprime en el prompt como `NO DEFINIDO`. Omitirlo dejaría silencio, y el
silencio lo completa el modelo con lo más probable de la categoría. Ésta es la
única regla del sistema anterior que se conserva, y es la que impide que el
asistente invente atributos de marca.

**No hay procedencia.** El asistente propone; una persona acepta. Todo lo que
está escrito lo escribió alguien, así que no hay nada que marcar.

---

## 2. Los 48 entregables

Las cinco tablas del PDF suman **101 filas** y **48 entregables distintos**:
los 6 del tier A se repiten literales en B, C y D, los de B en C y D, y los de
C en D. Lo que sigue es la unión deduplicada.

**La obligatoriedad varía por tier en cuatro casos** — `Análisis digital`,
`Manifesto`, `Lineamientos` y `Claim` son opcionales en B y obligatorios en C
y D. Como los tiers no se guardan, quedan **opcionales**: si hay un tier donde
la marca no lo necesita, marcarlo obligatorio le mentiría a cualquier marca de
ese tier.

| # | Entregable | |
|---|---|---|
| 1 | Arquetipos de marca (2 arquetipos) | obligatorio |
| 2 | Valores de marca (5 valores) | obligatorio |
| 3 | Relato de marca | obligatorio |
| 4 | Tono de comunicación | obligatorio |
| 5 | Temas de conversación con el cliente | obligatorio |
| 6 | Emblemas de marca | obligatorio |
| 7 | Territorio de marca | obligatorio |
| 8 | Análisis digital de la marca y 3 competidores | opcional |
| 9 | Análisis de la categoría | obligatorio |
| 10 | Prisma de Kapferer | opcional |
| 11 | Look and feel | obligatorio |
| 12 | Manifesto | opcional |
| 13 | Lineamientos de comunicación | opcional |
| 14 | Claim | opcional |
| 15 | Arquitectura de marca | opcional |
| 16 | Públicos | obligatorio |
| 17 | Insight principal de marca | obligatorio |
| 18 | Brand promise | obligatorio |
| 19 | Brand statement | obligatorio |
| 20 | Brand X | obligatorio |
| 21 | Campaña paid | opcional |
| 22 | Pilares de contenido | opcional |
| 23 | Distribución de contenido | opcional |
| 24 | Idea de evento | opcional |
| 25 | Do's and don'ts de influencers | opcional |
| 26 | Banco de ideas de contenido | opcional |
| 27 | Optimización de perfiles sociales | opcional |
| 28 | Checklist de implementación | obligatorio |
| 29 | Referencias de contenido en video o foto | opcional |
| 30 | Branded content (idea) | opcional |
| 31 | Perfil del contenido (branded content) | opcional |
| 32 | Framework cultural (branded content) | opcional |
| 33 | Service design: fase awareness | opcional |
| 34 | Service design: fase interacción | opcional |
| 35 | Service design: fase consideración | opcional |
| 36 | Service design: fase compra | opcional |
| 37 | Service design: fase service | opcional |
| 38 | Service design: fase loyalty expansion | opcional |
| 39 | Contexto y simbología | obligatorio |
| 40 | Brand universe (gráfico) | obligatorio |
| 41 | Definición de identificativo principal | obligatorio |
| 42 | Definición de identificativo secundario | opcional |
| 43 | Tipografía | opcional |
| 44 | Colores | obligatorio |
| 45 | Aplicaciones | opcional |
| 46 | Personaje | opcional |
| 47 | Audiologo | opcional |
| 48 | Ilustraciones | opcional |

**19 obligatorios, 29 opcionales.**

⚠️ El PDF del equipo dice 20/28. Tipografía pasó a opcional el 2026-08-23, a
pedido de Breakfast (ERR-07 del informe de revisión): no toda marca recibe una
tipografía propia, y estando obligatoria una vacía se imprimía como NO DEFINIDO,
que es lo que hizo que Brandy le anunciara al cliente "nos falta definir la
tipografía oficial".

Obligatorio/opcional es la **única** clasificación. No hay bloques, no hay
niveles, no hay grupos: agrupar exigiría inventar categorías que el PDF no
tiene, y las que salían solas chocaban con los nombres de los pasos. El
formulario del admin es una lista plana en este orden, con filtros
(todos / obligatorios / pendientes / llenos).

⚠️ **Hoy la obligatoriedad no bloquea nada.** No condiciona los pasos —el
admin completa un paso cuando quiere, con los entregables que sea— ni la
suscripción ni el acceso. Hace exactamente una cosa: decidir **cómo se le
anuncia al modelo un entregable vacío** (ver §8). Es una etiqueta, y está
puesta para que signifique algo el día que el equipo decida que signifique
algo, no para que hoy le diga a nadie que no puede avanzar.

Los 48 existen para toda marca. Un entregable que esta marca no va a recibir
se queda vacío para siempre, y no promete nada porque **el cliente nunca ve
esta lista**.

---

## 3. Los tres pasos

```
1 · Identidad de marca
2 · Territorio
3 · Toolkit
```

Lineales y manuales. El admin arranca el paso 1; al marcarlo completo, el 2
arranca solo. **No cambian nada más**: no habilitan entregables, no bloquean
secciones, no calculan permisos. Son la narrativa del proyecto — lo que el
cliente mira para saber que hay avance.

**Se completan independientemente de los entregables.** Un paso se puede
cerrar con cero entregables llenos o con los 48. Nada valida nada: el admin
sabe cuándo terminó un paso, y el sistema no tiene una opinión mejor que la
suya.

Que sean puramente narrativos es lo que los hace confiables: los mueve una
persona, así que no pueden mentir. Y como no se cruzan con los entregables, el
equipo puede producir lo que sea en el paso que sea sin que el modelo se
rompa.

`client_process_steps` guarda `started_at` y `completed_at` por paso. De ahí
sale la línea de tiempo, y cualquier cálculo de ritmo — sin depender de cron,
porque son fechas en una fila.

---

## 4. Modelo

```
DeliverableItem  enum   48 casos → label(), isRequired(), hint()
ProcessStep      enum   Arquitectura · Territorio · Toolkit

brand_deliverables    client_id (unique), + 48 columnas TEXT NULL
client_process_steps  client_id, step, started_at, completed_at, completed_by
brand_assets          client_id, uploaded_by, original_name,
                      disk, path, mime, size_bytes
meetings              client_id, title, agenda, scheduled_at, link,
                      notes, created_by, cancelled_at
notifications         la bandeja del portal
```

**Una fila por marca, una columna por entregable.** Sin estado y sin
procedencia, un entregable no es más que su texto: lo que queda es
`client_id` más 48 valores, que es un objeto y no 48. La fila *es* la marca, y
se lee entera en cualquier cliente de base de datos.

`DeliverableItem::Relato->value === 'relato'` **es** el nombre de la columna.
Un solo vocabulario, sin tabla de mapeo: el formulario, el prompt y el
contador recorren el enum y leen `$deliverables->{$item->value}`.

Es `BrandProfile` con 48 columnas reales en vez de un JSON, y el vocabulario
de 42 campos cambiado por el de 48 entregables. Igual que él, se lee con un
`brandDeliverablesOrNew()`: una marca que nadie ha tocado tiene que pintar el
formulario y reportar 0, no reventar con null.

El costo es que agregar o renombrar un entregable es una migración corriendo
en la terminal de cPanel. Está aceptado: la taxonomía viene de un documento
cerrado del equipo, no se mueve sola.

⚠️ Las 48 van **TEXT**, no VARCHAR. MySQL limita la fila a 65.535 bytes y un
VARCHAR grande cuenta completo contra ese límite; un TEXT deja sólo un puntero.

`ProcessStep` no aparece en `brand_deliverables`. A propósito.

---

## 5. Assets

Una carpeta por marca, con todo lo que se sube dentro:

```
storage/app/marcas/{slug}/assets/
```

El admin sube archivos de la marca — unos corresponden a un entregable y otros
no. **Todos** aparecen en la pantalla de archivos del cliente. Cuando un
archivo corresponde a un entregable, su link se escribe en el `content` de ese
entregable; si son varias imágenes, son varios links.

⚠️ **Los links apuntan a una ruta, no a `public/`.** Un archivo bajo `public/`
en iFastNet lo lee cualquiera que adivine la URL, y el brandbook de una marca
filtrándose a otra es el peor bug que puede tener esta app. Se sirven por un
controlador detrás de `section:brand-assets`, que además da URLs estables
aunque el archivo se reemplace.

⚠️ `storage:link` no es de fiar en ese hosting. Por eso `storage/app/`, no
`storage/app/public/`.

`ContextDocument` no se toca: son documentos que **entran** (brandbooks y
briefs que manda el cliente para alimentar al asistente). Los assets **salen**.
Direcciones opuestas.

---

## 6. Flujo del admin — `/admin/clientes/{marca}/proceso`

Una pantalla, tres zonas:

- **Los pasos.** Tres tarjetas. *Iniciar* en la que toca, *Marcar completo* en
  la que corre; completar dispara la siguiente.
- **Los entregables.** Los 48 en lista plana, cada uno con su textarea. Se
  guarda y ya está: no hay botón de "entregar" porque no hay estado que mover.
  El contador de arriba dice cuántos obligatorios llevan contenido.
- **Las reuniones.** Alta con fecha, link y agenda; próximas y pasadas con sus
  notas.

Al crear una reunión los participantes vienen marcados por defecto —los
usuarios de la marca más el staff asignado— y **avisar viene activado**. El
admin puede desmarcarlo.

⚠️ **Nada drena la cola.** Los avisos se mandan **síncronos**, como
`InviteUserToClient::sendSetupLink()`, que captura sus propios fallos en vez de
tirar la excepción. Una reunión que se guardó y no se pudo avisar sigue
guardada, y la pantalla lo dice.

---

## 7. Flujo del cliente

El cliente **no ve la lista de entregables**. Ve cuatro cosas:

- **`/portal`** — la barra de tres pasos y la próxima reunión si la hay.
- **Archivos** — todo lo subido a la carpeta de la marca.
- **La marca** — la página que se va escribiendo sola: renderiza los
  entregables que tienen contenido. Empieza vacía y crece con el trabajo. Es
  el brandbook armándose en vivo.
- **El asistente**, con todo lo anterior de contexto.

Un entregable vacío no existe para el cliente. No hay huecos visibles ni
promesas de lo que falta.

---

## 8. El asistente

`BrandContextRepository` arma el contexto con los entregables llenos, los
obligatorios vacíos como `NO DEFINIDO`, y un bloque de proceso:

```
## Proceso del proyecto

Paso actual: 2 · Territorio (desde el 4 de agosto de 2026)
Paso 1 · Identidad de marca: completo el 3 de agosto de 2026
Entregables: 11 de 24 obligatorios definidos; 3 opcionales.
Próxima reunión: jueves 20 de agosto de 2026, 11:00 — Revisión de territorio.
```

Con eso responde solo las preguntas que motivaron todo esto: *¿cuánto falta de
nuestra marca?*, *¿cuándo es la próxima reunión?*, *¿más o menos cuándo
terminamos?*

⚠️ **La invariante de caché manda.** Todo esto va en el **bloque 2**, que tiene
que ser byte-estable por cliente. Entonces:

- **Sólo fechas absolutas.** Nunca "faltan 3 días", nunca `now()`.
- **La fecha de hoy va en el bloque 3**, antepuesta al turno del usuario. Ahí
  vive lo volátil, no cuesta nada, y es lo que le permite al modelo hacer la
  aritmética él mismo: *"llevan 11 días y 11 de 24, a ese ritmo faltarían unas
  dos semanas, aunque cada proceso es distinto."*

Meter la fecha en el bloque 2 baja el cache hit a cero en silencio, sin error,
y multiplica el costo ~150x. Hay un test que verifica la estabilidad del
prefijo; si lo rompes, el test tiene razón.

---

## 9. Avisos

Correo **más** bandeja en el portal, con la campana en el layout — así sirve
después a assets y suscripción sin volver a construirla. El correo sale
síncrono; la fila de la bandeja se escribe en la misma transacción que el
evento, así que existe aunque el correo falle.

Avisan: reunión creada, reprogramada, cancelada; asset nuevo.

⚠️ **Nada de recordatorios que dependan del cron.** El cron de cPanel puede no
existir. Si algún día hay recordatorio de 24h, es un extra que puede no llegar,
nunca la única forma de enterarse.

---

## 10. Qué reemplaza

| se va | lo sustituye |
|---|---|
| `BrandField` (42 casos) | `DeliverableItem` (48 casos) |
| `BrandBlock` (9 bloques) | nada — lista plana |
| `BrandFieldLevel` (4 niveles) | `DeliverableItem::isRequired()` |
| `BrandFieldSource`, `ProposalAction` | nada — el asistente propone, alguien acepta |
| `BrandProfile` + `brand_profiles` | `brand_deliverables` |
| `brand-tracker.blade.php` | el contador del tablero |
| `docs/plantilla-contexto-de-marca.md` | este documento |

**No se dropea nada todavía.** `brand_profiles` se queda en su tabla, intacta,
hasta que el equipo confirme que no hay nada dentro que valga la pena migrar.
La app deja de escribirla; borrarla es una migración aparte y una decisión
aparte.

---

## 11. Fases

0. ~~**Aprobar la lista.**~~ ✅ Los 48 confirmados por el equipo, con los
   nombres de columna. Ninguno de los cinco posibles duplicados lo era.
1. ~~**La taxonomía.**~~ ✅ `DeliverableItem`, `ProcessStep`,
   `BrandDeliverables`, `ClientProcessStep`, `AdvanceProcessStep`, las dos
   migraciones y 20 tests.
2. ~~**El admin.**~~ ✅ `/admin/clientes/{marca}/proceso`: los tres pasos y el
   tablero de 48 entregables, con filtros y contador en vivo. 13 tests.
3. ~~**Assets.**~~ ✅ `storage/app/marcas/{slug}/assets/`, subida múltiple
   desde la pantalla de proceso, botón **Copiar link** para pegar la URL en el
   entregable que toque, y `/portal/brand-assets` de sólo lectura.
   Una sola ruta sirve a los dos lados y `User::canReachBrandAsset()` decide
   quién puede. 16 tests.
4. ~~**Reuniones.**~~ ✅ Alta, edición, cancelar y eliminar desde la pantalla
   de proceso; aviso por correo y/o bandeja, elegido por el admin; tarjeta de
   próxima reunión en `/portal`; `/portal/reuniones` de sólo lectura; campana
   en el layout. 20 tests.
   **Sin tabla de participantes**: el público de una reunión es quien puede
   leer Reuniones, decidido por `Client::meetingAudience()`. Una segunda lista
   sería una segunda respuesta que alguien tendría que mantener al día.
5. ~~**El cliente.**~~ ✅ Barra de tres pasos en `/portal` y en
   `/portal/proyecto`, `/portal/estrategia` escribiéndose sola con los
   entregables llenos, archivos y próxima reunión. Todo de sólo lectura: en
   estas secciones no hay control de edición ni siquiera deshabilitado,
   porque el equipo de la marca nunca las escribe. 14 tests.
6. ~~**El asistente.**~~ ✅ Los 48 son el contexto de marca; el pipeline de
   propuestas habla `DeliverableItem`; la fecha de hoy va en el bloque 3; y el
   bloque de proceso —paso actual, fechas de cada paso, cuenta de entregables
   y próxima reunión— viaja en el bloque 2, sin un solo reloj. El breakpoint
   de caché para Gemini está puesto en el último bloque estable del prefijo.
7. ~~**La limpieza.**~~ ✅ Retirados `BrandField`, `BrandBlock`,
   `BrandFieldLevel`, `BrandFieldSource`, `ProposalAction`, `BrandProfile`,
   `BrandSchema`, `BrandExtractor`, `FieldProposal`, `DraftTranscript`,
   `CollectsBrandAnswers`, la pantalla de perfil y el formulario de 42 campos
   de `/clientes/nueva`. `brand_profiles` sigue en la base, intacta.

Las ocho fases están hechas. La 7 se adelantó a la 3 porque tener dos sistemas
midiendo lo mismo en la misma pantalla confundía más de lo que protegía.

Lo que sigue no es de este documento: suscripciones (ver `suscripciones.md`),
extracción de texto de PDF y DOCX en `BrandContextRepository`, y las cinco
secciones del portal que siguen siendo stubs —Entregas, Contenido, Cronopost,
Reportes e IA Studio.
