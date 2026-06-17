<?php
/**
 * Error de transporte (red/timeout) al contactar un nodo RPC.
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

use RuntimeException;

final class TransportException extends RuntimeException {
}
