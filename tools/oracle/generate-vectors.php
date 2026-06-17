<?php
/**
 * Genera vectores golden con el oráculo mahdiyari/hive-php.
 *
 * Construye transacciones DETERMINISTAS (sin red, sin aleatoriedad) y vuelca su
 * serialización byte-a-byte, el digest de firma, el trx_id y la firma canónica.
 * El resultado se guarda como fixture en tests/fixtures/golden-vectors.json, que
 * SÍ se commitea: así nuestros tests corren sin depender del oráculo (gitignorado).
 *
 * Uso: php tools/oracle/generate-vectors.php
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Hive\Helpers\Serializer;
use Hive\Helpers\PrivateKey;
use Hive\Helpers\Transaction;

/** Chain id de Hive (HF24+). */
const HIVE_CHAIN_ID = 'beeab0de00000000000000000000000000000000000000000000000000000000';

/** Clave privada de PRUEBA fija (no es la de nadie): sha256 de una cadena conocida. */
$testPrivHex = hash('sha256', 'wpbp-test-key-do-not-use');

$serializer = new Serializer();
$privKey    = new PrivateKey($testPrivHex, true); // true = ya es hex.
$pubKey     = $privKey->createPublic();

/**
 * Serializa + firma una transacción y devuelve el vector.
 *
 * @param array{0:string,1:object} $operation
 */
function makeVector(Serializer $serializer, PrivateKey $privKey, array $txParams, array $operation): array {
    $trx                   = new Transaction();
    $trx->ref_block_num    = $txParams['ref_block_num'];
    $trx->ref_block_prefix = $txParams['ref_block_prefix'];
    $trx->expiration       = $txParams['expiration'];
    $trx->extensions       = [];
    $trx->signatures       = [];
    $trx->operations       = [$operation];

    $buffer = '';
    $serializer->TransactionSerializer($buffer, $trx);

    $digest    = hash('sha256', hex2bin(HIVE_CHAIN_ID . $buffer));
    $trxId     = substr(hash('sha256', hex2bin($buffer)), 0, 40);
    $signature = $privKey->sign($digest);

    return [
        'tx'         => $txParams,
        'operation'  => [ $operation[0], (array) $operation[1] ],
        'serialized' => $buffer,
        'digest'     => $digest,
        'trx_id'     => $trxId,
        'signature'  => $signature,
    ];
}

$txParams = [
    'ref_block_num'    => 4901,           // uint16
    'ref_block_prefix' => 2018936457,     // uint32
    'expiration'       => '2026-06-14T18:00:00',
];

// Vector principal: comment (post raíz) hacia el tag "hive-167922".
$commentOp = [
    'comment',
    (object) [
        'parent_author'  => '',
        'parent_permlink' => 'hive-167922',
        'author'         => 'skunk1',
        'permlink'       => 'hola-mundo-desde-wordpress',
        'title'          => 'Hola mundo',
        'body'           => "# Hola\n\nEste post viene de **WordPress** vía wp-blockchain-publish.",
        'json_metadata'  => json_encode(
            [
                'tags'   => [ 'hive-167922', 'wordpress', 'blog' ],
                'app'    => 'wp-blockchain-publish/0.1.0',
                'image'  => [ 'https://skunk1.blog/wp-content/uploads/img.png' ],
                'format' => 'markdown',
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
    ],
];

// Vector secundario: comment con caracteres multibyte (UTF-8) en título/cuerpo.
$utf8Op = [
    'comment',
    (object) [
        'parent_author'  => '',
        'parent_permlink' => 'hive-167922',
        'author'         => 'skunk1',
        'permlink'       => 'acentos-y-emojis',
        'title'          => 'Café, ñandú y 🚀',
        'body'           => 'Texto con acentos: áéíóú, ñ, y un emoji 🚀 para probar longitudes en bytes.',
        'json_metadata'  => json_encode( [ 'tags' => [ 'español' ], 'app' => 'wp-blockchain-publish/0.1.0' ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
    ],
];

// Vector terciario: comment_options con beneficiaries (tx de 2 ops como en la
// publicación real: comment + comment_options). Aquí solo el comment_options.
// Beneficiaries ORDENADOS por cuenta (lo exige la cadena). Pesos en centésimas
// de % (puntos base): 500 = 5%, 1000 = 10%.
$commentOptionsOp = [
    'comment_options',
    (object) [
        'author'                 => 'skunk1',
        'permlink'               => 'hola-mundo-desde-wordpress',
        'max_accepted_payout'    => '1000000.000 HBD',
        'percent_hbd'            => 10000,
        'allow_votes'            => true,
        'allow_curation_rewards' => true,
        'extensions'             => [
            [
                0,
                (object) [
                    'beneficiaries' => [
                        (object) [ 'account' => 'algun-proyecto', 'weight' => 1000 ],
                        (object) [ 'account' => 'un-curador', 'weight' => 500 ],
                    ],
                ],
            ],
        ],
    ],
];

$vectors = [
    'meta' => [
        'generated_by'  => 'mahdiyari/hive-php v1.1.1 (oracle)',
        'chain_id'      => HIVE_CHAIN_ID,
        'test_priv_hex' => $testPrivHex,
        'test_priv_wif' => $privKey->stringKey,
        'test_pub_key'  => $pubKey->stringKey ?? (string) $pubKey,
        'note'          => 'Clave de PRUEBA fija. Vectores deterministas para validar nuestra serialización/firma.',
    ],
    'comment_post'              => makeVector( $serializer, $privKey, $txParams, $commentOp ),
    'comment_utf8'              => makeVector( $serializer, $privKey, $txParams, $utf8Op ),
    'comment_options_benef'     => makeVector( $serializer, $privKey, $txParams, $commentOptionsOp ),
];

$outDir = __DIR__ . '/../../tests/fixtures';
if ( ! is_dir( $outDir ) ) {
    mkdir( $outDir, 0777, true );
}
$outFile = $outDir . '/golden-vectors.json';
file_put_contents( $outFile, json_encode( $vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

echo "Vectores escritos en: $outFile\n";
echo "Clave pública de prueba: " . ( $vectors['meta']['test_pub_key'] ) . "\n";
echo "comment_post.serialized (primeros 80 hex): " . substr( $vectors['comment_post']['serialized'], 0, 80 ) . "\n";
echo "comment_post.signature: " . $vectors['comment_post']['signature'] . "\n";
