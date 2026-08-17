<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

sample_print(sample_client()->payments()->cardTaxonomy());
