<?php

namespace App\Services\SalesOrderImport;

use RuntimeException;

/**
 * An order sheet that can't be read — the message is shown to the user.
 */
class SheetParseException extends RuntimeException
{
}
