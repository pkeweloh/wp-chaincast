<?php
/**
 * DTO neutro de contenido a publicar.
 *
 * Es independiente de cualquier cadena: cada conector lo traduce a su formato
 * concreto (la comment op de graphene, una publicación EVM, etc.). El núcleo
 * construye este objeto a partir de la entrada de WordPress.
 *
 * @package Chaincast\Connector
 */

declare(strict_types=1);

namespace Chaincast\Connector;

final class PostPayload {

    /**
     * @param string               $title         Título de la entrada.
     * @param string               $body          Cuerpo en Markdown.
     * @param string[]             $tags          Etiquetas (la 1ª suele ser la categoría principal).
     * @param string[]             $images        URLs absolutas de imágenes.
     * @param string               $author        Autor en la cadena (p. ej. cuenta Hive).
     * @param string               $canonicalUrl  Enlace canónico de vuelta a la web (SEO/atribución).
     * @param int                  $wpPostId      ID de la entrada en WordPress (idempotencia).
     * @param array<int,array{account:string,weight:int}> $beneficiaries Lista validada y ordenada
     *        de reparto de recompensas (weight en puntos base). Vacía = 100% autor.
     * @param array<string,mixed>  $extra         Datos adicionales por-conector (override de tags, etc.).
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $tags,
        public readonly array $images,
        public readonly string $author,
        public readonly string $canonicalUrl,
        public readonly int $wpPostId,
        public readonly array $beneficiaries = [],
        public readonly array $extra = [],
    ) {
    }
}
