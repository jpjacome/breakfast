<?php

namespace App\Enums;

/**
 * One of the 48 things Breakfast produces for a brand.
 *
 * Source of truth: docs/entregables.md, which extracts them from the team's
 * "Definición de entregables Breakfast" PDF. That PDF holds five tables — the
 * service tiers A to E — totalling 101 rows, but the tiers nest inside each
 * other, so the 48 below are the deduplicated union. The tiers themselves are
 * NOT modelled: they only ever served to decide what is required.
 *
 * The case value is the COLUMN NAME in brand_deliverables. One vocabulary, no
 * mapping table: the form, the prompt and the counter all walk this enum and
 * read $deliverables->{$item->value}. Renaming a case is therefore a migration
 * plus a data change, not a rename.
 *
 * This replaces BrandField. The 42 fields described a brand's *inputs*; these
 * 48 are what the agency actually *delivers*, and they are what the assistant
 * now reads as its whole knowledge of the brand.
 */
enum DeliverableItem: string
{
    case Arquetipos = 'arquetipos';
    case Valores = 'valores';
    case Relato = 'relato';
    case Tono = 'tono';
    case TemasConversacion = 'temas_conversacion';
    case Emblemas = 'emblemas';
    case Territorio = 'territorio';
    case AnalisisDigital = 'analisis_digital';
    case AnalisisCategoria = 'analisis_categoria';
    case PrismaKapferer = 'prisma_kapferer';
    case LookAndFeel = 'look_and_feel';
    case Manifesto = 'manifesto';
    case Lineamientos = 'lineamientos';
    case Claim = 'claim';
    case Arquitectura = 'arquitectura';
    case Publicos = 'publicos';
    case Insight = 'insight';
    case BrandPromise = 'brand_promise';
    case BrandStatement = 'brand_statement';
    case BrandX = 'brand_x';
    case CampanaPaid = 'campana_paid';
    case PilaresContenido = 'pilares_contenido';
    case DistribucionContenido = 'distribucion_contenido';
    case IdeaEvento = 'idea_evento';
    case Influencers = 'influencers';
    case BancoIdeas = 'banco_ideas';
    case PerfilesSociales = 'perfiles_sociales';
    case ChecklistImplementacion = 'checklist_implementacion';
    case ReferenciasContenido = 'referencias_contenido';
    case BrandedContentIdea = 'branded_content_idea';
    case BrandedContentPerfil = 'branded_content_perfil';
    case BrandedContentFramework = 'branded_content_framework';
    case ServiceDesignAwareness = 'service_design_awareness';
    case ServiceDesignInteraccion = 'service_design_interaccion';
    case ServiceDesignConsideracion = 'service_design_consideracion';
    case ServiceDesignCompra = 'service_design_compra';
    case ServiceDesignService = 'service_design_service';
    case ServiceDesignLoyalty = 'service_design_loyalty';
    case ContextoSimbologia = 'contexto_simbologia';
    case BrandUniverse = 'brand_universe';
    case IdentificativoPrincipal = 'identificativo_principal';
    case IdentificativoSecundario = 'identificativo_secundario';
    case Tipografia = 'tipografia';
    case Colores = 'colores';
    case Aplicaciones = 'aplicaciones';
    case Personaje = 'personaje';
    case Audiologo = 'audiologo';
    case Ilustraciones = 'ilustraciones';

    /**
     * Label, whether it is required, and what to write in it.
     *
     * One match instead of three: an entregable's definition reads as a single
     * row, which is how it is actually thought about.
     *
     * @return array{label: string, required: bool, hint: string}
     */
    public function definition(): array
    {
        return match ($this) {
            self::Arquetipos => [
                'label' => 'Arquetipos de marca',
                'required' => true,
                'hint' => 'Dos arquetipos. Por cada uno: cuál es y dónde manda — en qué territorio, canal o momento se expresa.',
            ],
            self::Valores => [
                'label' => 'Valores de marca',
                'required' => true,
                'hint' => 'Cinco valores, uno por línea, cada uno con qué significa en la práctica. Un valor sin conducta es un adjetivo.',
            ],
            self::Relato => [
                'label' => 'Relato de marca',
                'required' => true,
                'hint' => 'La historia que la marca cuenta de sí misma. De dónde viene, por qué existe, a dónde va.',
            ],
            self::Tono => [
                'label' => 'Tono de comunicación',
                'required' => true,
                'hint' => 'Atributos del tono, cada uno CON SU LÍMITE. Ej.: "Directo — decimos el precio en la primera respuesta. No confundir con brusco."',
            ],
            self::TemasConversacion => [
                'label' => 'Temas de conversación con el cliente',
                'required' => true,
                'hint' => 'De qué habla la marca con su gente, y de qué no.',
            ],
            self::Emblemas => [
                'label' => 'Emblemas de marca',
                'required' => true,
                'hint' => 'Los signos que la marca hace suyos: objetos, gestos, palabras, colores que la identifican sin firmar.',
            ],
            self::Territorio => [
                'label' => 'Territorio de marca',
                'required' => true,
                'hint' => 'El terreno cultural que la marca ocupa y defiende.',
            ],
            self::AnalisisDigital => [
                'label' => 'Análisis digital de la marca y 3 competidores',
                'required' => false,
                'hint' => 'Qué hace la marca hoy en digital y qué hacen tres competidores. Obligatorio en los tiers C y D.',
            ],
            self::AnalisisCategoria => [
                'label' => 'Análisis de la categoría',
                'required' => true,
                'hint' => 'Cómo se comporta la categoría: códigos que todos repiten, y dónde hay espacio.',
            ],
            self::PrismaKapferer => [
                'label' => 'Prisma de Kapferer',
                'required' => false,
                'hint' => 'Las seis facetas: físico, personalidad, cultura, relación, reflejo, automagen.',
            ],
            self::LookAndFeel => [
                'label' => 'Look and feel',
                'required' => true,
                'hint' => 'La sensación visual, en palabras. El asistente no ve imágenes: si no está escrito, no existe para él.',
            ],
            self::Manifesto => [
                'label' => 'Manifesto',
                'required' => false,
                'hint' => 'La declaración de la marca, en su propia voz. Obligatorio en los tiers C y D.',
            ],
            self::Lineamientos => [
                'label' => 'Lineamientos de comunicación',
                'required' => false,
                'hint' => 'Las reglas de cómo se comunica la marca. Obligatorio en los tiers C y D.',
            ],
            self::Claim => [
                'label' => 'Claim',
                'required' => false,
                'hint' => 'La frase que acompaña al identificativo. Obligatorio en los tiers C y D.',
            ],
            self::Arquitectura => [
                'label' => 'Arquitectura de marca',
                'required' => false,
                'hint' => 'Sub-marcas y productos: cómo se llaman y cómo se relacionan. Sólo si hay más de una.',
            ],
            self::Publicos => [
                'label' => 'Públicos',
                'required' => true,
                'hint' => 'Al menos un segmento. Por cada uno: quién es, qué necesita, qué le frena.',
            ],
            self::Insight => [
                'label' => 'Insight principal de marca',
                'required' => true,
                'hint' => 'La verdad incómoda sobre la que se construye todo lo demás.',
            ],
            self::BrandPromise => [
                'label' => 'Brand promise',
                'required' => true,
                'hint' => 'Qué promete la marca, en una frase que se pueda incumplir. Si no se puede incumplir, no es una promesa.',
            ],
            self::BrandStatement => [
                'label' => 'Brand statement',
                'required' => true,
                'hint' => 'La declaración que ordena todo: qué es la marca, para quién y por qué importa.',
            ],
            self::BrandX => [
                'label' => 'Brand X',
                'required' => true,
                'hint' => 'El factor propio de la marca: lo que no se puede copiar aunque se explique.',
            ],
            self::CampanaPaid => [
                'label' => 'Campaña paid',
                'required' => false,
                'hint' => 'La idea de campaña pagada: mensaje, audiencia, formato.',
            ],
            self::PilaresContenido => [
                'label' => 'Pilares de contenido',
                'required' => false,
                'hint' => 'Los territorios de contenido y qué porcentaje ocupa cada uno.',
            ],
            self::DistribucionContenido => [
                'label' => 'Distribución de contenido',
                'required' => false,
                'hint' => 'Qué se publica dónde, con qué frecuencia y en qué formato.',
            ],
            self::IdeaEvento => [
                'label' => 'Idea de evento',
                'required' => false,
                'hint' => 'El evento que la marca podría hacer suyo.',
            ],
            self::Influencers => [
                'label' => "Do's and don'ts de influencers",
                'required' => false,
                'hint' => 'Con quién sí y con quién no, y qué se les pide y qué no.',
            ],
            self::BancoIdeas => [
                'label' => 'Banco de ideas de contenido',
                'required' => false,
                'hint' => 'Ideas concretas listas para producir, una por línea.',
            ],
            self::PerfilesSociales => [
                'label' => 'Optimización de perfiles sociales',
                'required' => false,
                'hint' => 'Bio, nombre, destacados y enlaces por plataforma.',
            ],
            self::ChecklistImplementacion => [
                'label' => 'Checklist de implementación',
                'required' => true,
                'hint' => 'Qué hay que cambiar, dónde y en qué orden para que la marca nueva llegue a todos lados.',
            ],
            self::ReferenciasContenido => [
                'label' => 'Referencias de contenido en video o foto',
                'required' => false,
                'hint' => 'Referencias de producción: qué se parece a lo que queremos. Descríbelas — el asistente no ve imágenes.',
            ],
            self::BrandedContentIdea => [
                'label' => 'Branded content (idea)',
                'required' => false,
                'hint' => 'La idea de contenido de marca, en una línea que se entienda sola.',
            ],
            self::BrandedContentPerfil => [
                'label' => 'Perfil del contenido (branded content)',
                'required' => false,
                'hint' => 'Formato, duración, tono y plataforma del branded content.',
            ],
            self::BrandedContentFramework => [
                'label' => 'Framework cultural (branded content)',
                'required' => false,
                'hint' => 'La corriente cultural en la que el contenido se enchufa.',
            ],
            self::ServiceDesignAwareness => [
                'label' => 'Service design: fase awareness',
                'required' => false,
                'hint' => 'Cómo descubre la marca alguien que no la conoce.',
            ],
            self::ServiceDesignInteraccion => [
                'label' => 'Service design: fase interacción',
                'required' => false,
                'hint' => 'El primer contacto real y qué tiene que pasar en él.',
            ],
            self::ServiceDesignConsideracion => [
                'label' => 'Service design: fase consideración',
                'required' => false,
                'hint' => 'Qué necesita saber alguien que ya está evaluando.',
            ],
            self::ServiceDesignCompra => [
                'label' => 'Service design: fase compra',
                'required' => false,
                'hint' => 'El momento de la compra: fricciones que quitar, señales que dar.',
            ],
            self::ServiceDesignService => [
                'label' => 'Service design: fase service',
                'required' => false,
                'hint' => 'Qué pasa después de comprar, y cómo se comporta la marca ahí.',
            ],
            self::ServiceDesignLoyalty => [
                'label' => 'Service design: fase loyalty expansion',
                'required' => false,
                'hint' => 'Cómo se queda la gente y cómo trae a más.',
            ],
            self::ContextoSimbologia => [
                'label' => 'Contexto y simbología',
                'required' => true,
                'hint' => 'De dónde salen los símbolos de la identidad y qué quieren decir.',
            ],
            self::BrandUniverse => [
                'label' => 'Brand universe (gráfico)',
                'required' => true,
                'hint' => 'El universo gráfico de la marca. Descríbelo en palabras y deja aquí el link a la pieza.',
            ],
            self::IdentificativoPrincipal => [
                'label' => 'Definición de identificativo principal',
                'required' => true,
                'hint' => 'Qué es el identificativo, cómo se usa, y el link al archivo en la carpeta de la marca.',
            ],
            self::IdentificativoSecundario => [
                'label' => 'Definición de identificativo secundario',
                'required' => false,
                'hint' => 'La versión alterna y cuándo se usa en lugar de la principal. Link al archivo.',
            ],
            self::Tipografia => [
                'label' => 'Tipografía',
                // Optional since 2026-08-23, at Breakfast's request. Not every
                // brand gets a typeface of its own, and being required meant an
                // empty one was printed as NO DEFINIDO — which is what put "nos
                // falta definir la tipografía oficial" in front of a client.
                // See isRequired(): that flag decides how an absence is
                // announced to the model and nothing else.
                'required' => false,
                // The "sólo si el material la nombra" half is aimed at the
                // assistant, which was reading the typeface a brandbook happens
                // to be SET in and proposing it as the brand's own. That is
                // usually the template's font, not the marca's.
                'hint' => 'Las familias, sus pesos y para qué sirve cada una. Nombra la fuente: "la de los títulos" no le sirve a nadie. Sólo se llena si el material la nombra por escrito; una fuente reconocida a ojo no cuenta.',
            ],
            self::Colores => [
                'label' => 'Colores',
                'required' => true,
                'hint' => 'Cada color en hex, con su nombre y su uso. Un hex vale más que un adjetivo.',
            ],
            self::Aplicaciones => [
                'label' => 'Aplicaciones',
                'required' => false,
                'hint' => 'Reglas concretas por soporte: papelería, empaque, digital, señalética.',
            ],
            self::Personaje => [
                'label' => 'Personaje',
                'required' => false,
                'hint' => 'Quién es, cómo se comporta y dónde aparece. Link a los archivos.',
            ],
            self::Audiologo => [
                'label' => 'Audiologo',
                'required' => false,
                'hint' => 'Cómo suena la marca y dónde se usa. Deja el link al audio.',
            ],
            self::Ilustraciones => [
                'label' => 'Ilustraciones',
                'required' => false,
                'hint' => 'El estilo de ilustración, sus reglas, y los links a las piezas.',
            ],
        };
    }

    public function label(): string
    {
        return $this->definition()['label'];
    }

    public function hint(): string
    {
        return $this->definition()['hint'];
    }

    /**
     * Whether an empty value is a hole or just an absence.
     *
     * DELIBERATELY GATES NOTHING TODAY. It does not decide when a process step
     * can close, what the client is shown, or what the subscription includes —
     * the admin closes a step when they say so. It does exactly one thing:
     * decide how an empty entregable is announced to the model. See
     * BrandDeliverables::toMarkdown().
     *
     * The four that vary by tier (analisis_digital, manifesto, lineamientos,
     * claim) are false. They are optional in tier B and required in C and D,
     * and since tiers are not stored, calling them required would lie to every
     * brand on the smaller tier.
     */
    public function isRequired(): bool
    {
        return $this->definition()['required'];
    }

    /**
     * The 20 whose absence is stated as NO DEFINIDO.
     *
     * @return array<int, self>
     */
    public static function required(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $item) => $item->isRequired(),
        ));
    }

    /**
     * The 28 whose absence is collected into one closing line.
     *
     * @return array<int, self>
     */
    public static function optional(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $item) => ! $item->isRequired(),
        ));
    }

    /** @return array<int, string> Every column name, for the migration and $fillable. */
    public static function columns(): array
    {
        return array_column(self::cases(), 'value');
    }
}
