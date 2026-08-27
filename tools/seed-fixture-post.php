<?php
/**
 * Seeds the posts behind tests/fixtures/wordpress-content.html: one published on
 * Hive with a permlink its slug does not predict, one published nowhere, and one
 * that links to both and carries every block the converter handles.
 *
 * Usage (from docker/):
 *   docker compose exec -T wpcli wp eval-file  *     wp-content/plugins/chaincast/tools/seed-fixture-post.php
 *
 * Then dump the third post's rendered HTML with tools/dump-fixture.php.
 *
 * @package Chaincast\Tools
 */

$first = wp_insert_post(
    [
        'post_title'   => 'Quien controla el clima',
        'post_name'    => 'quien-controla-el-clima',
        'post_status'  => 'publish',
        'post_content' => '<!-- wp:paragraph --><p>Primera entrega.</p><!-- /wp:paragraph -->',
    ],
    true
);

$second = wp_insert_post(
    [
        'post_title'   => 'La sequia es una decision',
        'post_name'    => 'la-sequia-es-una-decision',
        'post_status'  => 'publish',
        'post_content' => '<!-- wp:paragraph --><p>Segunda entrega.</p><!-- /wp:paragraph -->',
    ],
    true
);

// Published on Hive with a permlink the slug does not predict; nothing on Steem.
update_post_meta(
    $first,
    '_chaincast_state_hive',
    [
        'status' => 'published',
        'ref'    => 'quien-controla-el-clima-10',
        'url'    => 'https://hive.blog/@demo-author/quien-controla-el-clima-10',
    ]
);

$firstUrl  = get_permalink( $first );
$secondUrl = get_permalink( $second );

$content = <<<HTML
<!-- wp:heading -->
<h2 class="wp-block-heading">Lo que se conto antes</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Como se conto en <a href="{$firstUrl}">la primera entrega</a>, y tambien en <a href="{$secondUrl}">la segunda</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Enlace desnudo a la primera: <a href="{$firstUrl}">{$firstUrl}</a></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Una linea<br>y la de abajo, separadas por un salto duro.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item --><li>Primer punto.</li><!-- /wp:list-item --><!-- wp:list-item --><li>Segundo punto.</li><!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Una cita textual.</p><!-- /wp:paragraph --></blockquote>
<!-- /wp:quote -->

<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Ano</th><th>Lluvia</th></tr></thead><tbody><tr><td>2024</td><td>312 mm</td></tr><tr><td>2025</td><td>188 mm</td></tr></tbody></table><figcaption class="wp-element-caption">Serie de la cuenca.</figcaption></figure>
<!-- /wp:table -->

<!-- wp:paragraph -->
<p>Fuente: <a href="https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524">https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524</a></p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<figure class="wp-block-embed"><div class="wp-block-embed__wrapper"><iframe src="https://play.3speak.tv/embed?v=demo-author/abcdefg" width="640" height="360" allowfullscreen></iframe></div></figure>
<!-- /wp:html -->

<!-- wp:paragraph -->
<p>Video rotulado: <a href="https://www.youtube.com/watch?v=abcdefghijk">https://www.youtube.com/watch?v=abcdefghijk</a></p>
<!-- /wp:paragraph -->

<!-- wp:video -->
<figure class="wp-block-video"><video controls src="http://localhost:8089/wp-content/uploads/2026/08/clip.mp4"></video><figcaption class="wp-element-caption">Grabacion propia.</figcaption></figure>
<!-- /wp:video -->

<!-- wp:file -->
<div class="wp-block-file"><a id="wp-block-file--media-1" href="http://localhost:8089/wp-content/uploads/2026/08/informe.pdf">informe.pdf</a><a href="http://localhost:8089/wp-content/uploads/2026/08/informe.pdf" class="wp-block-file__button wp-element-button" download aria-describedby="wp-block-file--media-1">Descargar</a></div>
<!-- /wp:file -->

<!-- wp:image -->
<figure class="wp-block-image"><img src="http://localhost:8089/wp-content/uploads/2026/08/foto.jpg" alt="Una foto"/><figcaption class="wp-element-caption">Pie de foto.</figcaption></figure>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>Descarga el <a href="http://localhost:8089/wp-content/uploads/2026/08/informe.pdf">informe en PDF</a>.</p>
<!-- /wp:paragraph -->
HTML;

$third = wp_insert_post(
    [
        'post_title'   => 'Tercera entrega, la que enlaza a las otras',
        'post_name'    => 'tercera-entrega',
        'post_status'  => 'publish',
        'post_content' => $content,
    ],
    true
);

WP_CLI::line( 'first=' . $first . ' second=' . $second . ' third=' . $third );
