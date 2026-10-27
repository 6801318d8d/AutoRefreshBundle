<?php

namespace KimaiPlugin\AutoRefreshBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Refreshes open Kimai pages when timesheets change elsewhere (see README.md).
 */
class AutoRefreshBundle extends Bundle implements PluginInterface
{
}
