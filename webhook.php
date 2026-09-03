<?php

declare(strict_types=1);

/**
 * نقطه‌ی ورود وبهوک برای هاست‌های اشتراکی: این تنها فایلی است که لازم است از
 * بیرون قابل دسترسی باشد (بقیه‌ی پروژه بهتر است بیرون از public_html بماند).
 */

require_once __DIR__ . '/bootstrap.php';

teamkan_handle_webhook();
