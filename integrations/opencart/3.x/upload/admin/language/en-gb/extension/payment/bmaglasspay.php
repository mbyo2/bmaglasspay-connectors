<?php
// Heading
$_['heading_title'] = 'BMaGlass Pay';
// Text
$_['text_extension'] = 'Extensions';
$_['text_home'] = 'Home';
$_['text_edit'] = 'Edit BMaGlass Pay';
$_['text_success'] = 'Success: BMaGlass Pay settings saved.';
$_['text_connected'] = 'Connected to BMaGlass Pay.';
$_['text_test_key_notice'] = 'This is a TEST key: read-only — checkout needs a live key.';
$_['text_test'] = 'Test connection';
// Entry
$_['entry_status'] = 'Status';
$_['entry_api_key'] = 'BMaGlass API key';
$_['entry_webhook_secret'] = 'Webhook signing secret';
$_['entry_order_status'] = 'Paid order status';
$_['entry_sort_order'] = 'Sort order';
$_['entry_webhook_url'] = 'Webhook URL (register this in BMaGlass Pay)';
// Help
$_['help_api_key'] = 'bmg_live_... for checkout (legacy bmp_live_... keys keep working). Sandbox keys (bmg_sandbox_...) use simulated money: connection check and simulated payments only. The connector configures itself from the key — environment and features come from your BMaGlass account.';
$_['help_webhook_secret'] = 'Shown once when you register the webhook in BMaGlass Pay.';
$_['help_webhook_url'] = 'Settlement is push-first: the signed payment_link.paid webhook completes the order the moment its link is paid; API reconciliation runs as the fallback, so orders still complete if a delivery is missed.';
// Error
$_['error_permission'] = 'Warning: you do not have permission to modify BMaGlass Pay.';
