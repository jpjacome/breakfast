# Suscripciones — plan de implementación

Cómo Breakfast regala 1–2 meses de portal a una marca, le avisa antes de que
se acabe, y la deja pagar sola cuando decide continuar.

Decisiones tomadas antes de escribir esto:

- **Sin tarjeta durante la prueba.** Admin crea la marca, elige los meses de
  regalo, y Stripe no se entera hasta que alguien decide pagar.
- **Un solo plan, mensual.** El esquema guarda un `stripe_price_id`, así que
  agregar niveles después es configuración, no migración.
- **USD, solo tarjeta.** El pago confirma en el momento; no hay que manejar
  métodos asíncronos (OXXO, SEPA) todavía.

---

## 1. La idea de fondo

**El derecho a entrar se calcula con fechas, no con una columna que un cron
tiene que acordarse de voltear.**

Es la decisión más importante del documento. En iFastNet el `schedule:run` es
una entrada de cron en cPanel que puede no existir, puede quedar apagada tras
una migración de servidor, o puede fallar en silencio una semana. Si el acceso
dependiera de que un job escriba `status = 'vencida'`, una marca podría seguir
usando el asistente —y quemando créditos de API— dos meses después de que su
prueba terminó.

Entonces:

- `Subscription::entitlement()` compara `now()` contra `trial_ends_at`,
  `grace_ends_at` y `current_period_ends_at`. Es una función pura de la fila.
  Siempre correcta, corra o no el cron.
- La columna `status` es **caché legible**: existe para poder listar y filtrar
  en el admin sin calcular en PHP fila por fila. El cron la sincroniza.
- Los correos sí dependen del cron. Si el cron no corre, el cliente no recibe
  aviso pero tampoco pierde el acceso de golpe — falla del lado amable.

La segunda decisión de fondo: **el pago lo otorga el webhook, nunca la página
de regreso de Stripe.** Volver de Checkout con `?success=1` no prueba nada;
esa URL se puede escribir a mano.

---

## 2. Estados

`App\Enums\SubscriptionStatus` — mismo patrón que `ClientStatus` (valor,
`label()`, `badgeClass()`).

| valor | qué significa | portal |
|---|---|---|
| `prueba` | meses de regalo corriendo, sin tarjeta | completo |
| `activa` | pagando (Stripe o manual) | completo |
| `pendiente` | se acabó la prueba o falló un cobro; ventana de gracia | completo + banner rojo |
| `vencida` | se acabó la gracia | solo Suscripción, Perfil, Ayuda |
| `cancelada` | no renueva; sigue pagada hasta `current_period_ends_at` | completo hasta la fecha, luego `vencida` |

La ventana de gracia (7 días por defecto, `config/billing.php`) existe porque
cortarle el acceso a una marca el mismo día que se venció el regalo es una
forma rara de pedir dinero. Durante `pendiente` todo sigue funcionando con un
aviso encima.

```
prueba ──(trial_ends_at)──▶ pendiente ──(grace_ends_at)──▶ vencida
   │                            │                             │
   └──── Checkout ──────────────┴────────── Checkout ─────────┘
                                │
                                ▼
                             activa ──(cancela)──▶ cancelada ──▶ vencida
                                ▲                                  │
                                └────────── Checkout ──────────────┘
```

Activar durante la prueba **no quita los días que quedan**: la suscripción se
crea con `trial_end = trial_ends_at`, así que Stripe cobra el primer peso el
día que el regalo terminaba. Si no, activar temprano sería un castigo.

---

## 3. Base de datos

### `subscriptions` — una por marca

```php
$table->id();
$table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();

$table->string('status')->default('prueba')->index();

// Fechas: la fuente de verdad del acceso. Ver entitlement().
$table->timestamp('trial_ends_at')->nullable();
$table->timestamp('grace_ends_at')->nullable();
$table->timestamp('current_period_ends_at')->nullable();
$table->timestamp('canceled_at')->nullable();

// 'stripe' | 'manual' — una agencia también cobra por transferencia,
// y esas marcas no deben aparecer nunca en el flujo de Checkout.
$table->string('billing_mode')->default('stripe');

$table->string('stripe_customer_id')->nullable()->index();
$table->string('stripe_subscription_id')->nullable()->unique();
$table->string('stripe_price_id')->nullable();

$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamps();
```

`client_id` es `unique`: una marca, una suscripción. `hasOne`, igual que
`brand_profiles`.

### `subscription_events` — bitácora, dedupe e idempotencia en una tabla

```php
$table->id();
$table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

// 'creada' | 'prueba-extendida' | 'aviso-t14' | 'aviso-t7' | 'aviso-t1'
// | 'prueba-terminada' | 'acceso-pausado' | 'pagada' | 'cobro-fallido'
// | 'cancelada' | 'activada-manualmente'
$table->string('kind')->index();

$table->string('stripe_event_id')->nullable()->unique();
$table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
$table->text('summary')->nullable();
$table->json('payload')->nullable();
$table->timestamps();
```

Una tabla hace tres trabajos, y los tres son la misma pregunta —«¿ya pasó
esto?»:

1. **Timeline** en el admin: quién dio la prueba, cuándo se avisó, cuándo pagó.
2. **Dedupe de avisos**: antes de mandar `aviso-t7` se verifica que no exista.
   Si el cron corre dos veces, o dos veces en el mismo día, el correo sale una
   sola vez.
3. **Idempotencia de webhooks**: `stripe_event_id` es `unique`. Stripe
   reintenta los webhooks; el insert duplicado revienta y se responde 200 sin
   volver a aplicar nada.

### Nada más

**No se guardan facturas.** El historial, el cambio de tarjeta y la
cancelación viven en el **Stripe Billing Portal**, que Stripe hospeda,
mantiene en español y certifica en PCI. Nuestra página tiene un botón que
abre una sesión de ese portal y ya.

Espejar facturas en una tabla propia sería ~150 líneas y un webhook más para
reproducir peor algo que ya existe. Si algún día quieren las facturas con la
marca de Breakfast adentro del portal, esa tabla se agrega entonces —el
`stripe_customer_id` ya está guardado y no hay nada que rehacer.

---

## 4. Stripe: SDK directo, no Cashier

**Recomendación: `stripe/stripe-php` directo.**

Se usan exactamente tres superficies de Stripe:

1. `Checkout\Session::create(mode: 'subscription')` — activar.
2. `BillingPortal\Session::create()` — tarjeta, facturas, cancelar.
3. Webhooks — cinco eventos.

Cashier trae migraciones propias, un modelo `Subscription` que compite con el
nuestro, y su valor real está en prorrateos, cambios de plan, cantidades y
métricas — justamente lo que decidimos no construir. Serían ~200 líneas
nuestras contra una dependencia con opinión sobre nuestro modelo de datos, en
un repo que ya prefirió un enum nativo antes que spatie/laravel-permission
por la misma razón.

*El contraargumento honesto:* Cashier tiene `onGenericTrial()`, que es
exactamente el modelo de prueba sin tarjeta que elegimos, y su
`WebhookController` ya está probado en producción por mucha gente. Si el plan
crece a varios niveles con cambios de plan y prorrateo, Cashier vuelve a ser
la respuesta correcta y migrar es reescribir estas ~200 líneas.

### Configuración

`config/billing.php`:

```php
return [
    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'price'          => env('STRIPE_PRICE_MONTHLY'),
    ],
    'currency'         => 'usd',
    'grace_days'       => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
    'reminder_days'    => [14, 7, 1],   // días antes de que termine la prueba
    'default_trial_months' => 2,
];
```

⚠️ **Estas variables van en los DOS `.env`.** `portal/.env` con las llaves de
test (`sk_test_…`) y `portal/tests/.env` con las de producción — ese último es
el archivo que se sube al servidor. Editar solo el local no cambia nada en
vivo. El `STRIPE_WEBHOOK_SECRET` es **distinto** en cada ambiente: Stripe da
uno por endpoint registrado.

---

## 5. Admin — crear la marca con su regalo

### En `admin/clientes/nueva`

Un campo más en el formulario, junto a estado e industria:

```
Prueba gratis   [ 2 meses ▾ ]   Sin prueba / 1 mes / 2 meses / 3 meses
```

`StoreClientRequest` valida `trial_months` como `integer|min:0|max:12`.
`ClientController::store()` ya envuelve todo en una transacción —la
suscripción se crea ahí adentro, con la marca y el perfil:

```php
$client->subscription()->create([
    'status'        => $months > 0 ? SubscriptionStatus::Prueba : SubscriptionStatus::Pendiente,
    'trial_ends_at' => $months > 0 ? now()->addMonths($months)->endOfDay() : null,
    'grace_ends_at' => ...,
    'created_by'    => $request->user()->id,
]);
```

`endOfDay()` a propósito: «dos meses» que se vencen a las 14:32 del martes
porque a esa hora se creó la cuenta es una mezquindad que nadie va a agradecer.

Marcas que ya existen hoy: la migración les crea una suscripción `activa` en
modo `manual` con `trial_ends_at = null`. Nadie pierde el acceso el día del
deploy — el cambio empieza a aplicar solo para las marcas nuevas y para las
que un admin convierta a mano.

### En `admin/clientes/{client}` — tarjeta de suscripción

Estado, fecha de vencimiento, días restantes, y las acciones:

- **Extender prueba** (+1 mes) — para cuando el cliente pide más tiempo.
- **Activar manualmente** — pagó por transferencia; `billing_mode = manual`,
  `current_period_ends_at = +1 mes`. Nunca toca Stripe.
- **Pausar / cancelar acceso**.
- **Reenviar aviso** — dispara el correo del estado actual, a mano.
- **Timeline** — los `subscription_events` en orden.

### Nueva pantalla `admin/suscripciones`

La lista que Breakfast va a abrir cada lunes: todas las marcas ordenadas por
días restantes, filtrable por estado, con las que vencen esta semana arriba.
Sin esto, «avisarle al cliente» depende de que alguien se acuerde.

También un contador en el dashboard: «3 pruebas terminan esta semana».

---

## 6. Avisos

Un comando, diario, en `routes/console.php`:

```php
Schedule::command('subscriptions:send-reminders')->dailyAt('09:00');
```

### Qué se manda

| cuándo | a quién | evento |
|---|---|---|
| 14, 7 y 1 día antes de `trial_ends_at` | dueño de marca | `aviso-t14/t7/t1` |
| día de `trial_ends_at` | dueño + `info@` | `prueba-terminada` |
| día de `grace_ends_at` | dueño + `info@` | `acceso-pausado` |
| cobro fallido (webhook) | dueño | `cobro-fallido` |

Los recibos de renovación los manda Stripe. No se duplican.

Después de `acceso-pausado` el sistema se calla. Un correo semanal a una
marca que decidió no seguir es spam con nuestro dominio.

### Cómo no se manda dos veces, ni se salta

El comando no pregunta «¿hoy es el día 7?» sino **«¿cuál es el aviso más
urgente que ya tocaba y todavía no mandé?»**, y manda ese. Si el cron estuvo
caído tres días, la marca recibe el `aviso-t1` —el que corresponde a hoy— en
lugar de nada. Y el `subscription_events` garantiza que cada `kind` sale una
sola vez por suscripción.

El mismo comando sincroniza la columna `status` con lo que las fechas ya
dicen. Es su trabajo secundario; el acceso no lo espera.

### Correo síncrono, no encolado

`QUEUE_CONNECTION=database` pero **en iFastNet no hay un `queue:work`
corriendo**. Si estas notificaciones fueran `ShouldQueue`, se escribirían en
la tabla `jobs` y no saldrían nunca.

Se mandan síncronas, igual que `ClientInvitation` hoy. El comando diario
manda pocos correos y puede tardar unos segundos sin que nadie lo note.

*(La alternativa, si algún día hace falta: agregar
`Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()`.
No es necesario para esto.)*

### En el portal, no solo en el correo

Un banner en el layout del portal —días restantes, o «tu prueba terminó»— con
botón directo a pagar. El correo se pierde entre 200 correos; la barra que
ven cada vez que entran, no.

---

## 7. Cortar el acceso

`App\Http\Middleware\EnsureBrandSubscriptionAllows`, sobre **todo** el grupo
`/portal`.

```php
Route::middleware(['auth', 'subscription'])->prefix('portal')->name('portal.')
```

Reglas:

- **Staff de Breakfast pasa siempre.** Dan soporte; una marca vencida es
  precisamente cuando más falta hace entrar a verla.
- **Suscripción, Perfil y Ayuda nunca se bloquean.** Bloquear la página de
  pago porque no ha pagado sería un chiste. Se agrega a `PortalSection` un
  `requiresSubscription(): bool` que devuelve `false` para esas tres, del
  mismo estilo que `isOwnerOnly()` / `isAlwaysOn()`.
- **Redirect, no 404.** `EnsurePortalSectionAccess` da 404 a propósito —un
  miembro no debe enterarse de que existe Cronopost. Esto es distinto: la
  marca ya sabe que existe, dejó de pagar. Redirige a
  `portal.suscripcion` con el mensaje. Un 404 aquí sería mentir.

### Lo que de verdad importa cerrar

**Los endpoints del asistente.** Cada turno es una llamada pagada a la API.
Una marca vencida que sigue mandando prompts nos cuesta dinero real. El
middleware va sobre el grupo entero justamente para que ninguna ruta nueva
—de IA o de lo que sea— nazca sin la reja.

Las rutas de `/admin` no cambian: ya están detrás de `breakfast`.

---

## 8. `/portal/suscripcion` — la página del cliente

Reemplaza el stub `portal.section` por un controlador real. Una vista, cinco
caras según el estado:

**`prueba`** — «Prueba gratis · quedan 23 días», la fecha exacta, qué pasa
después, y **Activar plan** (no pierde los días restantes; se dice en la
página). Precio y ciclo visibles.

**`activa`** — plan, monto, próximo cobro, método de pago. Botón
**Administrar facturación** → Billing Portal (tarjeta, facturas, cancelar).

**`pendiente`** — «Tu prueba terminó el X. Tu acceso sigue abierto hasta el
Y.» + **Activar plan**.

**`vencida`** — página de pago, con lo demás del portal cerrado.

**`cancelada`** — «Activa hasta el X» + **Reactivar**.

### Rutas

```php
Route::get('/suscripcion',           [SubscriptionController::class, 'show'])->name('suscripcion');
Route::post('/suscripcion/checkout', [SubscriptionCheckoutController::class, 'store'])
    ->middleware('throttle:10,1')->name('suscripcion.checkout');
Route::get('/suscripcion/gracias',   [SubscriptionCheckoutController::class, 'thanks'])->name('suscripcion.thanks');
Route::post('/suscripcion/portal',   [SubscriptionBillingPortalController::class, 'store'])
    ->middleware('throttle:10,1')->name('suscripcion.billing-portal');
```

Siguen bajo `section:suscripcion`, que ya es exclusiva del dueño de marca —o
sea que un miembro no puede iniciar un pago. Ese candado ya existe.

`billing_mode = manual` **no ve el botón de pagar**: a esas marcas les cobra
Breakfast por fuera, y mandarlas a Checkout crearía una segunda suscripción
cobrando doble.

### La página de gracias no otorga nada

Vuelve de Stripe diciendo «estamos confirmando tu pago» y refresca. Cuando el
webhook llegó, la página ya muestra `activa`. Si alguien escribe la URL a
mano no pasa absolutamente nada. En la práctica el webhook llega antes que el
redirect, así que casi nadie ve el estado intermedio — pero tiene que existir.

---

## 9. Webhooks

```php
// routes/web.php — FUERA de cualquier grupo con auth
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
```

Y en `bootstrap/app.php`:

```php
$middleware->validateCsrfTokens(except: ['stripe/webhook']);
```

| evento | qué hace |
|---|---|
| `checkout.session.completed` | guarda `stripe_customer_id` y `stripe_subscription_id`, marca `activa` |
| `invoice.paid` | renovación: mueve `current_period_ends_at` |
| `invoice.payment_failed` | `pendiente` + gracia + correo al dueño |
| `customer.subscription.updated` | espeja `cancel_at_period_end` y las fechas |
| `customer.subscription.deleted` | `cancelada`, corre hasta `current_period_ends_at` |

Reglas no negociables:

1. **Verificar la firma** con `Webhook::constructEvent()` sobre el **cuerpo
   crudo** (`$request->getContent()`). Un endpoint sin firma es un botón
   público para regalar suscripciones.
2. **Idempotencia** por `stripe_event_id` unique. Stripe reintenta.
3. **Responder 200 rápido.** Si no reconocemos el evento, 200 igual — un
   4xx/5xx hace que Stripe reintente para siempre y termine desactivando el
   endpoint.
4. **Localizar la marca por `client_id` en `metadata`**, puesto al crear la
   sesión de Checkout. Nunca por email.

Los reintentos de cobro fallido (dunning) los maneja Stripe con su propia
configuración. Nosotros solo escuchamos el resultado.

---

## 10. Archivos

```
database/migrations/
  ….._create_subscriptions_table.php
  ….._create_subscription_events_table.php
  ….._backfill_subscriptions_for_existing_clients.php

app/Enums/SubscriptionStatus.php
app/Enums/SubscriptionEventKind.php
app/Enums/PortalSection.php                      (+ requiresSubscription)

app/Models/Subscription.php                      entitlement(), daysLeft(), scopes
app/Models/SubscriptionEvent.php
app/Models/Client.php                            (+ subscription(), subscriptionOrNew())

app/Actions/StartTrial.php
app/Actions/ExtendTrial.php
app/Actions/ActivateSubscriptionManually.php
app/Actions/SyncSubscriptionFromStripe.php

app/Services/Billing/StripeGateway.php           las 3 llamadas a Stripe
app/Services/Billing/SubscriptionReminders.php   qué aviso toca hoy

app/Http/Middleware/EnsureBrandSubscriptionAllows.php

app/Http/Controllers/Portal/SubscriptionController.php
app/Http/Controllers/Portal/SubscriptionCheckoutController.php
app/Http/Controllers/Portal/SubscriptionBillingPortalController.php
app/Http/Controllers/StripeWebhookController.php
app/Http/Controllers/Admin/SubscriptionController.php

app/Console/Commands/SendSubscriptionReminders.php

app/Notifications/TrialEndingSoon.php
app/Notifications/TrialEnded.php
app/Notifications/AccessPaused.php
app/Notifications/PaymentFailed.php

resources/views/portal/suscripcion.blade.php
resources/views/admin/subscriptions/index.blade.php
resources/views/components/portal/subscription-banner.blade.php
resources/css/suscripcion.css                    una hoja, para esa blade

config/billing.php
```

CSS: una hoja nueva para la blade del portal; lo del admin entra en
`admin.css`, que ya existe. Sin escalas de tokens nuevas, sin colores
inventados.

---

## 11. Orden de trabajo

**Fase 1 — El modelo y el regalo.** Migraciones, enums, `Subscription` con
`entitlement()`, el campo de meses en crear-marca, la tarjeta en la ficha de
la marca, backfill de las marcas actuales. *Todavía nada de Stripe.* Al
terminar: admin regala 2 meses y el sistema sabe cuándo se acaban.

**Fase 2 — Avisos y candado.** Comando diario, las cuatro notificaciones, el
middleware, el banner, la página `/portal/suscripcion` en modo informativo
(sin botón de pago). Al terminar: el cliente sabe que se le acaba y el acceso
se cierra solo. **Esto ya es útil aunque Stripe nunca se conecte** — con cobro
por transferencia el producto funciona completo.

**Fase 3 — Stripe.** SDK, `StripeGateway`, Checkout, webhooks, Billing
Portal, página de gracias. Al terminar: el cliente paga solo.

**Fase 4 — Operación.** `admin/suscripciones`, el contador en el dashboard,
activación manual, timeline, extender prueba.

Cortar después de la 2 deja un producto entero. Es la propiedad que hace que
valga la pena este orden.

---

## 12. Pruebas (Pest, junto a las existentes)

- Un `trial_months` de 2 crea la suscripción con la fecha correcta.
- `entitlement()` en cada estado y en cada frontera de fecha — incluido
  «el cron nunca corrió»: fechas vencidas + `status` viejo en la columna
  ⇒ el acceso se niega igual.
- El middleware corta `/portal/estrategia` y **deja pasar** `/portal/suscripcion`,
  `/portal/perfil`, `/portal/ayuda`.
- El middleware corta los endpoints del asistente (el que cuesta dinero).
- Staff de Breakfast entra a una marca vencida.
- Un miembro (no dueño) no puede iniciar Checkout.
- Un webhook sin firma válida: 400 y nada cambia.
- El mismo `stripe_event_id` dos veces: se aplica una sola vez.
- El comando de avisos no repite un `kind` ya mandado.
- El comando, tras 3 días caído, manda el aviso que toca hoy y no los tres.
- Volver a `/suscripcion/gracias` a mano no activa nada.
- Una marca `billing_mode = manual` no ve el botón de pagar.

---

## 13. Antes de la Fase 3, verificar en el servidor

1. **¿Existe el cron?** `* * * * * cd /home/<user>/portal && php artisan schedule:run`
   en cPanel. Sin él no sale ningún aviso. (El acceso se corta igual —por eso
   se calcula con fechas.)
2. **El endpoint del webhook** registrado en el dashboard de Stripe apuntando
   a `https://vamosdebreakfast.com/stripe/webhook`, y su secreto en
   `portal/tests/.env`.
3. **`.htaccess`** — el de la raíz es grande; confirmar que no bloquea POSTs
   externos a `/stripe/webhook` (sin cookie, sin referer, user-agent de Stripe).
4. **Que `portal/tests/.env` se haya subido** después de agregar las llaves.
   Es el paso que se olvida.
5. Probar con `stripe listen --forward-to` en local antes de tocar producción.
