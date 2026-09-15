# El asistente del admin

El portal tiene **dos asistentes, el mismo motor, contextos opuestos.** Este
documento es el del admin. El del cliente vive en `entregables.md`.

|  | asistente del cliente | asistente del admin |
|---|---|---|
| dónde | `/admin/clientes/{marca}/proceso` | `/admin` — el dashboard **es** el asistente |
| alcance | una marca, nunca ve otra | todas a la vez; es su razón de ser |
| fuente | los 48 entregables + documentos subidos | **la base de datos**: pasos, reuniones, archivos, consumo |
| produce | contenido de marca, propuestas de entregables | respuestas sobre el estado del negocio |
| riesgo principal | inventar atributos de marca | **inventar cifras y fechas** |
| defensa | `NO DEFINIDO` para lo que falta | toda cifra tiene que estar literal en el contexto |

Origen: `docs/plan-desarrollo.html` §03b, reconciliado con lo que existe hoy.
Ese archivo es HTML de la semana 1 y `CLAUDE.md` manda ignorarlo — correcto
para los wireframes, equivocado para esto. Por eso existe este `.md`.

---

## 1. La idea de fondo

**El contexto se genera desde la base, nunca desde los documentos.**

Volcarle los PDFs de doce marcas al modelo sería carísimo y además no
respondería ninguna de las preguntas que este asistente existe para contestar,
que son sobre el *estado* del negocio y no sobre el *contenido* de una marca.

En su lugar, `PortfolioSnapshot` arma una tabla compacta: cada marca con su
estado, su paso, sus entregables, su próxima reunión, su última actividad y su
gasto de IA. Con una docena de marcas son ~2.000 tokens y responde la mayoría
de lo que se le pregunta. Si la pregunta se estrecha a una marca, se le agrega
la ficha ampliada de ésa.

**La ficha se agrega, no reemplaza.** «¿Cómo va The Coffee Club comparada con
las demás?» nombra una marca y sigue necesitando el resto. El desplegable es
una pista sobre el foco, no un filtro.

**La ficha trae los 48 entregables con su texto**, no sólo cuántos están
llenos. La tabla dice que Alea va 18 de 48; la pregunta que de verdad se hace
es «¿ya tiene colores definidos?», y ésa sólo se contesta leyendo la columna.
Usa el mismo `toMarkdown()` que lee el asistente del cliente, así que los dos
lados describen una marca igual: lo lleno con su texto, lo obligatorio vacío
como `NO DEFINIDO`, y los opcionales vacíos juntos en la línea final.

**Hay dos formas de nombrar una marca: el desplegable y escribirla en la
pregunta.** `MentionedBrands` busca los nombres y los slugs de las marcas
*visibles para quien pregunta* dentro del texto, por palabra completa, y agrega
hasta dos fichas. Antes sólo servía el desplegable, así que en modo «todas las
marcas» —el que viene por defecto— cualquier pregunta sobre el contenido de una
marca se respondía «no tengo ese dato aquí» teniendo el dato en la base.

⚠️ Eso hace que el bloque 2 cambie con la pregunta y se pierdan aciertos de
caché en los turnos que nombran una marca. Es a propósito: una respuesta
equivocada en caché no vale nada. Dos turnos seguidos sobre la misma marca
siguen serializando igual y siguen pegando en el caché.

### El gasto va en el bloque 3, no en la tabla

`SpendDigest` arma el bloque de gasto: hoy, los últimos 30 días, y el desglose
por marca —incluida la línea «Sin marca», que es el gasto propio del panel y de
los borradores, no un cliente—. Lee `UsageStatistics`, la misma clase que el
tablero de `/admin`, para que las dos pantallas no puedan decir cifras
distintas.

⚠️ **Va pegado a la pregunta, junto a la fecha, y nunca en el panorama.**
Responder una pregunta escribe una fila en `ai_usage_logs`, así que cualquier
total ya está viejo en el turno siguiente. En el prefijo cacheable eso
cambiaría el prefijo en *todos* los turnos y tiraría los aciertos de caché a
cero —en silencio, como dice la trampa 4, con el costo ~150x—. Que el reporte
de gasto fuera justo lo que encarece cada petición es la razón de que esto sea
su propia clase y no dos líneas más en `PortfolioSnapshot`. La línea por marca
también se movió de ahí por lo mismo. Hay un test que lo fija.

---

## 2. Las tres reglas duras

**Sólo lectura.** No crea marcas, no mueve pasos, no agenda reuniones, no manda
invitaciones. Esas acciones viven en sus pantallas, donde se ve exactamente qué
se va a hacer antes de confirmarlo. *Un chat que ejecuta cambios es la forma más
rápida de mover la etapa del cliente equivocado.* No hay ruta de escritura en
`AdminAssistant` ni en nada que llame.

**Camino propio, no un `if`.** `AdminContextBuilder` es una clase aparte de
`BrandContextBuilder`. Aquél es por-cliente por construcción y nunca ve una
segunda marca; éste cruza todas las que el usuario pueda ver, que es justo lo
que el del cliente tiene prohibido. Dos clases separadas significa que un error
en una no puede volverse una filtración en la otra.

**Toda cifra y toda fecha, literal.** Es la defensa que pide su riesgo, como
`NO DEFINIDO` lo es del lado del cliente. El prompt lo dice explícitamente: no
estimar, no redondear, no sumar de cabeza. Contar, comparar y ordenar lo que sí
está en la tabla no es inventar: es leer.

⚠️ **El alcance se respeta.** `PortfolioSnapshot::forUser()` filtra por
`Client::visibleTo()`, el mismo scope que usan las pantallas. Sin eso, un
miembro de Equipo aprendería el roster completo preguntándole al asistente lo
que la lista de marcas le esconde. Un slug posteado a mano tampoco sirve: el
controlador lo resuelve por `visibleTo()` y, si no lo alcanza, cae a modo
portafolio en vez de dar error.

---

## 3. Modelo

```
PortfolioSnapshot     la tabla de todas las marcas + la ficha de una
AdminContextBuilder   arma los mensajes; camino separado del cliente
AdminAssistant        la única cara pública. Llama a esto.
AdminAssistantRequest valida y falla en JSON (ver §5)

POST /admin/asistente   throttle:20,1   → AdminAssistantController
```

El prompt del sistema es `config('ai.admin_prompt')`, aparte del
`system_prompt` del cliente.

### El orden de los bloques

```
bloque 1  prompt del admin        estable para siempre
bloque 2  panorama (+ ficha)      estable hasta que cambien los datos
bloque 3  hoy + la pregunta       volátil
```

Las mismas reglas de caché que del lado del cliente y por lo mismo. **Sólo
fechas absolutas en el bloque 2**: «hace tres días» cambiaría a diario y bajaría
el hit rate a cero en silencio. La fecha de hoy va pegada a la pregunta, y el
modelo hace la aritmética — que es exactamente lo que necesita «¿qué marcas
llevan más de un mes sin actividad?».

El breakpoint de caché de Gemini va en el último bloque estable del prefijo.

---

## 4. Lo que tiene que contestar bien

- ¿Qué marcas están trabadas y por qué?
- ¿Qué tengo esta semana?
- ¿Cuánto llevo gastado en IA este mes y qué marca consume más?
- ¿Qué marcas llevan más de un mes sin actividad?
- ¿En qué quedamos con The Coffee Club?
- Resúmeme la estrategia de esta marca en tres líneas.

**«Trabada» no es una columna.** El prompt le pide deducirlo de lo que sí hay
—sin actividad reciente, sin próxima reunión, obligatorios sin definir— y decir
en qué se basó. Verificado contra datos reales el 2026-08-13: nombró las tres
marcas, explicó el criterio, y no citó una sola cifra que no estuviera en la
tabla.

**«Última actividad» es lo más reciente de todo lo que cuenta como trabajo** —
entregables, pasos, reuniones, documentos, archivos. La fila del cliente sola no
sirve: una marca cuyo row no cambió desde que se creó pudo tener una reunión
ayer.

---

## 5. Trampa

⚠️ **`bootstrap/app.php` sólo renderiza JSON para rutas `api/*`.** Un
`$request->validate()` que falla en una ruta bajo `admin/` devuelve un
**redirect**, no un 422 — y a un `fetch()` eso le llega como un fallo mudo. Por
eso este endpoint usa un FormRequest con `failedValidation()` sobrescrito, igual
que `BrandOnboardingTurnRequest`. Cualquier endpoint nuevo movido por JavaScript
bajo `admin/` o `portal/` necesita lo mismo.

---

## 6. Lo que no se construyó

El plan original describía una tubería de cuatro pasos —reconocer, componer,
generar, verificar— con recetas versionadas en `config/ai/tasks/`. No existe.
El asistente del admin funciona sin ella porque su prompt lleva la regla de las
cifras literales directamente; si algún día se construye el verificador, esa
regla es su primera capa determinista.

Tampoco existe la tabla `PortfolioSnapshot` cacheada que el plan menciona: hoy
se calcula en cada turno con dos queries. Con doce marcas eso no se nota. Si
llega a notarse, cachearla e invalidarla al cambiar los datos es el paso
siguiente, no un rediseño.
