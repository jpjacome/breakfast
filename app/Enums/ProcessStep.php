<?php

namespace App\Enums;

/**
 * One of the three steps a brand walks through with Breakfast.
 *
 * Linear and manual. The admin starts step 1; marking it complete starts the
 * next one. That is the whole mechanism.
 *
 * THEY GATE NOTHING. A step does not enable entregables, unlock portal
 * sections or decide permissions, and a step can be closed with zero
 * entregables filled or with all 48 — nothing validates anything. They are
 * the narrative of the project: what the client looks at to know there is
 * movement.
 *
 * Being purely narrative is exactly what makes them trustworthy. A person
 * moves them, so they cannot disagree with reality. And because they are not
 * tied to the entregables, the team can produce whatever they need to in
 * whichever step they need to, without the model breaking.
 */
enum ProcessStep: string
{
    case Arquitectura = 'arquitectura';
    case Territorio = 'territorio';
    case Toolkit = 'toolkit';

    /** The number shown to the client: "Paso 2 de 3". */
    public function number(): int
    {
        return match ($this) {
            self::Arquitectura => 1,
            self::Territorio => 2,
            self::Toolkit => 3,
        };
    }

    /**
     * ⚠️ STEP 1 IS LABELLED DIFFERENTLY FROM ITS CASE. It shows as "Identidad
     * de marca"; the case and its value stay `arquitectura` because that value
     * is what sits in `client_process_steps.step` on every brand that has ever
     * started the step, and renaming it would be a data migration run by hand
     * in cPanel to change a word on screen. Same reasoning as PortalSection,
     * where Estrategia shows as "Tu marca".
     *
     * Do not confuse it with `DeliverableItem::Arquitectura`, which is a real
     * entregable still called "Arquitectura de marca" — sub-marcas y productos.
     * They are different things and now read as different things.
     */
    public function label(): string
    {
        return match ($this) {
            self::Arquitectura => 'Identidad de marca',
            self::Territorio => 'Territorio',
            self::Toolkit => 'Toolkit',
        };
    }

    /** One line telling the client what is happening to their brand right now. */
    public function description(): string
    {
        return match ($this) {
            self::Arquitectura => 'Definimos quién es la marca: sus arquetipos, sus valores y su relato.',
            self::Territorio => 'Encontramos el terreno que la marca ocupa y cómo se ve ahí.',
            self::Toolkit => 'Armamos las herramientas para que la marca viva todos los días.',
        };
    }

    /** Tabler icon name, rendered as <x-tabler-{icon}>. */
    public function icon(): string
    {
        return match ($this) {
            self::Arquitectura => 'building-arch',
            self::Territorio => 'map-2',
            self::Toolkit => 'tools',
        };
    }

    public function isFirst(): bool
    {
        return $this === self::Arquitectura;
    }

    public function isLast(): bool
    {
        return $this === self::Toolkit;
    }

    /** The step that starts when this one is marked complete, if any. */
    public function next(): ?self
    {
        return match ($this) {
            self::Arquitectura => self::Territorio,
            self::Territorio => self::Toolkit,
            self::Toolkit => null,
        };
    }

    public static function first(): self
    {
        return self::Arquitectura;
    }

    public static function count(): int
    {
        return count(self::cases());
    }
}
