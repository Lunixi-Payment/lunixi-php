<?php

declare(strict_types=1);

/*
 * Changes a link and pauses it.
 *
 * Every change creates a new version of the link. Pass the link's current
 * rowVersion as expectedRowVersion: if someone changed the link in between, the
 * gateway answers 409 `payment_link.link.version_conflict` instead of
 * overwriting their change — read the link again and retry.
 *
 * Amount fields are locked (409 `payment_link.link.amount_locked`) while a
 * payment is in progress or once the link has been paid. usage, amountMode and
 * currency can never change.
 */

require_once __DIR__ . '/../_common/bootstrap.php';

$links = sample_client()->paymentLinks();
$linkId = sample_required_env('LUNIXI_PAYMENT_LINK_ID');

$current = $links->get($linkId);
$updated = $links->update($linkId, $current->rowVersion(), [
    'title' => $current->title() . ' (güncellendi)',
    'successRedirectUrl' => null,
]);

$paused = $links->pause($linkId, $updated->rowVersion(), 'Stok sayımı');
sample_print(['version' => $paused->version(), 'rowVersion' => $paused->rowVersion(), 'state' => $paused->state()]);
