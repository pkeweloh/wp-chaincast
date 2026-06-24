<?php
/**
 * Transport error (network/timeout) when contacting an RPC node.
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

use RuntimeException;

final class TransportException extends RuntimeException {
}
