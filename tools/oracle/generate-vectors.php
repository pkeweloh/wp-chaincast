<?php
/**
 * Generates golden vectors with the mahdiyari/hive-php oracle.
 *
 * Builds DETERMINISTIC transactions (no network, no randomness) and dumps their
 * byte-by-byte serialization, the signing digest, the trx_id and the canonical
 * signature. The result is stored as a fixture in tests/fixtures/golden-vectors.json,
 * which IS committed: so our tests run without depending on the oracle (gitignored).
 *
 * Usage: php tools/oracle/generate-vectors.php
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Hive\Helpers\Serializer;
use Hive\Helpers\PrivateKey;
use Hive\Helpers\Transaction;

/** Hive chain id (HF24+). */
const HIVE_CHAIN_ID = 'beeab0de00000000000000000000000000000000000000000000000000000000';

/** Fixed TEST private key (nobody's): sha256 of a known string. */
$testPrivHex = hash('sha256', 'wpbp-test-key-do-not-use');

$serializer = new Serializer();
$privKey    = new PrivateKey($testPrivHex, true); // true = already hex.
$pubKey     = $privKey->createPublic();

/**
 * Serializes + signs a transaction and returns the vector.
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

// Primary vector: comment (root post) toward the "hive-167922" tag.
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

// Secondary vector: comment with multibyte (UTF-8) characters in title/body.
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

// Tertiary vector: comment_options with beneficiaries (a 2-op tx like a real
// publication: comment + comment_options). Here only the comment_options.
// Beneficiaries SORTED by account (the chain requires it). Weights in hundredths
// of a % (basis points): 500 = 5%, 1000 = 10%.
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
        'note'          => 'Fixed TEST key. Deterministic vectors to validate our serialization/signing.',
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

echo "Vectors written to: $outFile\n";
echo "Test public key: " . ( $vectors['meta']['test_pub_key'] ) . "\n";
echo "comment_post.serialized (first 80 hex): " . substr( $vectors['comment_post']['serialized'], 0, 80 ) . "\n";
echo "comment_post.signature: " . $vectors['comment_post']['signature'] . "\n";
