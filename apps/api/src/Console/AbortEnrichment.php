<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

/**
 * Stops an ai:build-product-attributes run early: the API key was rejected,
 * or too many products failed in a row (out of credit, outage). The message
 * is shown to the operator as-is.
 */
final class AbortEnrichment extends \RuntimeException
{
}
