<?php

declare(strict_types=1);

final class UpdateHandler
{
    public static function handle(TelegramApi $api, array $message, ?int $adminId): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $text = trim($message['text'] ?? '');

        if ($chatId === null || $text === '') {
            return;
        }

        // این دستور برای همه در دسترسه، حتی اگه ADMIN_ID تنظیم شده باشه.
        if (self::isCommand($text, '/status')) {
            $api->sendMessage($chatId, self::buildStatusText(), null, 'HTML');

            return;
        }

        $isAdmin = $adminId !== null && $fromId === $adminId;
        // پنل ادمین/ویرایش همیشه فقط برای خود ادمینه؛ بقیه‌ی دستورها اگه
        // «حالت عمومی» روشن باشه برای همه بازه، وگرنه فقط برای ادمین.
        $adminOnlyBlocked = $adminId !== null && !$isAdmin;
        $featuresBlocked = $adminOnlyBlocked && !Settings::isPublic();

        if ($isAdmin && self::handlePendingEdit($api, $chatId, $text)) {
            return;
        }

        if (self::handlePendingConversation($api, $chatId, $messageId, $text)) {
            return;
        }

        if (self::isCommand($text, '/admin')) {
            if ($adminOnlyBlocked) {
                return;
            }
            self::sendAdminPanel($api, $chatId);

            return;
        }

        if ($featuresBlocked) {
            return;
        }

        if (self::isCommand($text, '/start') || self::isCommand($text, '/help')) {
            self::sendMainMenu($api, $chatId);

            return;
        }

        if (self::isCommand($text, '/cancel')) {
            $api->sendMessage($chatId, 'چیزی برای لغو کردن نیست.');

            return;
        }

        self::routeCommand($api, $chatId, $messageId, $text);
    }

    public static function handleCallback(TelegramApi $api, array $callbackQuery, ?int $adminId): void
    {
        $callbackId = $callbackQuery['id'] ?? null;
        $data = $callbackQuery['data'] ?? '';
        $fromId = $callbackQuery['from']['id'] ?? null;
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;

        if ($callbackId !== null) {
            $api->answerCallbackQuery($callbackId);
        }
        if ($chatId === null) {
            return;
        }

        if ($data === 'status') {
            $api->sendMessage($chatId, self::buildStatusText(), null, 'HTML');

            return;
        }

        if ($adminId !== null && $fromId !== $adminId) {
            return;
        }

        if ($data === 'admin') {
            self::sendAdminPanel($api, $chatId);

            return;
        }

        if ($data === 'toggle_public') {
            Settings::setPublic(!Settings::isPublic());
            self::sendAdminPanel($api, $chatId);

            return;
        }

        if ($data === 'edit_welcome') {
            EditState::set(TextStore::KEY_WELCOME);
            $api->sendMessage($chatId, "✏️ متن جدید برای «پیام خوش‌آمد/راهنما» رو بفرستید (برای لغو: /cancel).\n\nمتن فعلی:\n" . TextStore::get(TextStore::KEY_WELCOME));

            return;
        }

        if ($data === 'help') {
            self::sendMainMenu($api, $chatId);

            return;
        }

        if (str_starts_with($data, 'connect_auth:')) {
            $authType = substr($data, strlen('connect_auth:'));
            self::advanceConnectAuth($api, $chatId, $authType);

            return;
        }

        $hints = [
            'connect' => '🔌 با /connect می‌تونید هاست، یوزرنیم و رمز/توکن cPanel رو مرحله‌به‌مرحله به ربات بدید.',
            'domains' => '🌐 بعد از اتصال، با /domains لیست دامنه/زیردامنه/addon/parked رو می‌بینید.',
            'email' => '📧 نمونه‌ها: /email list ، /email add foo@example.com "رمزقوی" 250 ، /email del foo@example.com',
            'db' => '🗄 نمونه‌ها: /db list ، /db create shopdb ، /db user add shopuser "رمزقوی" ، /db grant shopuser shopdb',
            'ftp' => '📁 نمونه‌ها: /ftp list ، /ftp add ftpuser "رمزقوی" 500 ، /ftp del ftpuser',
            'dns' => '🧭 نمونه‌ها: /dns list example.com ، /dns add example.com www A 1.2.3.4 ، /dns del example.com 12',
            'password' => '🔑 با /password می‌تونید با یک ویزارد کوتاه رمز اکانت cPanel رو عوض کنید.',
            'files' => '🗂 نمونه‌ها: /files list public_html ، /files view public_html/index.html ، /files write public_html/test.txt (بعدش محتوا رو در پیام بعدی بفرستید)',
            'ssh' => '💻 نمونه: /ssh df -h  — فقط با اتصال «رمز عبور» و اگه SSH روی هاست‌تون فعال باشه.',
        ];

        if (isset($hints[$data])) {
            $api->sendMessage($chatId, $hints[$data]);
        }
    }

    private static function routeCommand(TelegramApi $api, int $chatId, ?int $messageId, string $text): void
    {
        $cmd = strtolower(explode('@', strtok($text, " \t") ?: '')[0]);
        $args = self::argsAfter($text);

        switch ($cmd) {
            case '/connect':
                self::startConnectWizard($api, $chatId);

                return;
            case '/disconnect':
                self::cmdDisconnect($api, $chatId);

                return;
            case '/info':
            case '/accountinfo':
                self::cmdInfo($api, $chatId);

                return;
            case '/domains':
                self::cmdDomains($api, $chatId);

                return;
            case '/subdomain':
                self::cmdSubdomain($api, $chatId, $args);

                return;
            case '/email':
                self::cmdEmail($api, $chatId, $messageId, $args);

                return;
            case '/db':
                self::cmdDb($api, $chatId, $messageId, $args);

                return;
            case '/ftp':
                self::cmdFtp($api, $chatId, $messageId, $args);

                return;
            case '/dns':
                self::cmdDns($api, $chatId, $args);

                return;
            case '/password':
                self::startPasswordWizard($api, $chatId);

                return;
            case '/raw':
                self::cmdRaw($api, $chatId, $args);

                return;
            case '/files':
                self::cmdFiles($api, $chatId, $args);

                return;
            case '/ssh':
                self::cmdSsh($api, $chatId, $text);

                return;
            case '/sshport':
                self::cmdSshPort($api, $chatId, $args);

                return;
            default:
                if (str_starts_with($text, '/')) {
                    return;
                }
                self::sendMainMenu($api, $chatId);
        }
    }

    // ---------------------------------------------------------------
    // ویزارد /connect
    // ---------------------------------------------------------------

    private static function startConnectWizard(TelegramApi $api, int $chatId): void
    {
        ConversationState::set($chatId, 'connect_host', []);
        $api->sendMessage($chatId, "🔌 اتصال هاست جدید (اگه قبلاً وصل بودید، جایگزین می‌شه).\n\nآدرس هاست یا IP رو بفرستید (مثلاً example.com یا 123.45.67.89). برای لغو: /cancel");
    }

    private static function startPasswordWizard(TelegramApi $api, int $chatId): void
    {
        if (HostStore::get($chatId) === null) {
            $api->sendMessage($chatId, '❗️ ابتدا با /connect هاست‌تون رو وصل کنید.');

            return;
        }
        ConversationState::set($chatId, 'password_current', []);
        $api->sendMessage($chatId, '🔑 برای تغییر رمز اکانت cPanel، اول رمز فعلی رو بفرستید (بلافاصله از چت پاک می‌شه). برای لغو: /cancel');
    }

    private static function advanceConnectAuth(TelegramApi $api, int $chatId, string $authType): void
    {
        $state = ConversationState::get($chatId);
        if ($state === null || $state['step'] !== 'connect_authtype_wait') {
            return;
        }
        if (!in_array($authType, ['password', 'token'], true)) {
            return;
        }
        $data = $state['data'];
        $data['auth_type'] = $authType;
        ConversationState::set($chatId, 'connect_secret', $data);
        $label = $authType === 'token' ? 'API Token' : 'رمز عبور';
        $api->sendMessage($chatId, "🔐 حالا {$label} رو بفرستید (بلافاصله از چت پاک می‌شه تا جایی ذخیره نمونه).");
    }

    /**
     * @return bool اگه پیام مصرف شد (بخشی از یک ویزارد بود) true برمی‌گردونه.
     */
    private static function handlePendingConversation(TelegramApi $api, int $chatId, ?int $messageId, string $text): bool
    {
        $state = ConversationState::get($chatId);
        if ($state === null) {
            return false;
        }

        if ($text === '/cancel') {
            ConversationState::clear($chatId);
            $api->sendMessage($chatId, 'لغو شد.');

            return true;
        }

        $step = $state['step'];
        $data = $state['data'];

        // برای محتوای فایل، خودِ محتوا ممکنه با "/" شروع بشه (مثلاً یک لیست
        // مسیر یا robots.txt)؛ برخلاف بقیه‌ی مراحل نباید به‌عنوان دستور در نظر
        // گرفته بشه.
        if ($step !== 'files_write_content' && str_starts_with($text, '/')) {
            ConversationState::clear($chatId);

            return false;
        }

        switch ($step) {
            case 'connect_host':
                $host = preg_replace('#^https?://#i', '', trim($text));
                $host = rtrim((string) $host, '/');
                if ($host === '') {
                    $api->sendMessage($chatId, 'آدرس هاست نمی‌تونه خالی باشه. دوباره بفرستید:');

                    return true;
                }
                $data['host'] = $host;
                ConversationState::set($chatId, 'connect_port', $data);
                $api->sendMessage($chatId, 'پورت cPanel رو بفرستید (پیش‌فرض معمول: 2083). اگه مطمئن نیستید همین "-" رو بفرستید تا 2083 در نظر گرفته بشه.');

                return true;

            case 'connect_port':
                $trimmed = trim($text);
                if ($trimmed === '' || $trimmed === '-' || $trimmed === 'default') {
                    $port = 2083;
                } elseif (ctype_digit($trimmed) && (int) $trimmed >= 1 && (int) $trimmed <= 65535) {
                    $port = (int) $trimmed;
                } else {
                    $api->sendMessage($chatId, 'پورت باید یک عدد بین 1 تا 65535 باشه (یا "-" برای پیش‌فرض). دوباره بفرستید:');

                    return true;
                }
                $data['port'] = $port;
                ConversationState::set($chatId, 'connect_username', $data);
                $api->sendMessage($chatId, 'یوزرنیم اکانت cPanel رو بفرستید:');

                return true;

            case 'connect_username':
                $username = trim($text);
                if ($username === '') {
                    $api->sendMessage($chatId, 'یوزرنیم نمی‌تونه خالی باشه. دوباره بفرستید:');

                    return true;
                }
                $data['username'] = $username;
                ConversationState::set($chatId, 'connect_authtype_wait', $data);
                $api->sendMessage($chatId, 'روش احراز هویت رو انتخاب کنید:', [
                    'inline_keyboard' => [
                        [['text' => '🔑 رمز عبور cPanel', 'callback_data' => 'connect_auth:password']],
                        [['text' => '🪪 API Token', 'callback_data' => 'connect_auth:token']],
                    ],
                ]);

                return true;

            case 'connect_authtype_wait':
                $normalized = mb_strtolower(trim($text));
                if (in_array($normalized, ['password', 'رمز', 'رمز عبور'], true)) {
                    self::advanceConnectAuth($api, $chatId, 'password');
                } elseif (in_array($normalized, ['token', 'توکن'], true)) {
                    self::advanceConnectAuth($api, $chatId, 'token');
                } else {
                    $api->sendMessage($chatId, 'لطفاً از دکمه‌های بالا یکی رو انتخاب کنید (یا بنویسید password یا token).');
                }

                return true;

            case 'connect_secret':
                if ($messageId !== null) {
                    $api->deleteMessage($chatId, $messageId);
                }
                $secret = trim($text);
                if ($secret === '') {
                    $api->sendMessage($chatId, 'مقدار خالی بود؛ دوباره رمز/توکن رو بفرستید:');

                    return true;
                }
                $api->sendChatAction($chatId, 'typing');
                $client = new CpanelClient((string) $data['host'], (int) $data['port'], (string) $data['username'], $secret, (string) $data['auth_type']);
                $domainsData = [];
                $warning = null;
                try {
                    $domainsData = $client->uapi('DomainInfo', 'domains_data');
                } catch (CpanelConnectionError|CpanelAuthError $e) {
                    // این دو تا یعنی واقعاً اتصال/احراز هویت شکست خورده (هاست در
                    // دسترس نیست، یا یوزرنیم/رمز/توکن غلطه) — نباید ذخیره بشه.
                    ConversationState::clear($chatId);
                    $api->sendMessage($chatId, "⚠️ اتصال ناموفق بود: {$e->getMessage()}\n\nدوباره با /connect تلاش کنید.");

                    return true;
                } catch (CpanelError $e) {
                    // احراز هویت انجام شده (خودِ هاست به درخواست جواب داده)، ولی
                    // این تابع مشخص رد شده — مثلاً چون API Token فقط به یک ویژگی
                    // خاص (مثل Zone Edit / DNS) محدود شده. اتصال رو معتبر در نظر
                    // می‌گیریم، فقط هشدار می‌دیم که بعضی دستورها ممکنه کار نکنن.
                    $warning = $e->getMessage();
                }
                HostStore::save($chatId, (string) $data['host'], (int) $data['port'], (string) $data['username'], (string) $data['auth_type'], $secret);
                ConversationState::clear($chatId);
                $api->sendMessage($chatId, Render::connected((string) $data['host'], (int) $data['port'], (string) $data['username'], $domainsData, $warning), null, 'HTML');

                return true;

            case 'password_current':
                if ($messageId !== null) {
                    $api->deleteMessage($chatId, $messageId);
                }
                $data['current'] = $text;
                ConversationState::set($chatId, 'password_new', $data);
                $api->sendMessage($chatId, 'رمز جدید رو بفرستید (بلافاصله از چت پاک می‌شه):');

                return true;

            case 'password_new':
                if ($messageId !== null) {
                    $api->deleteMessage($chatId, $messageId);
                }
                self::finishPasswordChange($api, $chatId, (string) $data['current'], $text);
                ConversationState::clear($chatId);

                return true;

            case 'files_write_content':
                ConversationState::clear($chatId);
                self::finishFileWrite($api, $chatId, (string) $data['path'], $text);

                return true;
        }

        return false;
    }

    private static function finishFileWrite(TelegramApi $api, int $chatId, string $path, string $content): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        [$dir, $file] = self::splitPath($path);
        self::runUapi($api, $chatId, $client, 'Fileman', 'save_file_content', [
            'dir' => $dir,
            'file' => $file,
            'content' => $content,
        ], "✅ «{$path}» ذخیره شد.");
    }

    private static function finishPasswordChange(TelegramApi $api, int $chatId, string $oldPass, string $newPass): void
    {
        $profile = HostStore::get($chatId);
        if ($profile === null) {
            $api->sendMessage($chatId, '❗️ هاست وصل‌شده‌ای پیدا نشد.');

            return;
        }
        $client = CpanelClient::fromProfile($profile);
        $api->sendChatAction($chatId, 'typing');
        try {
            $client->api2('Passwd', 'change_password', [
                'user' => $profile['username'],
                'oldpass' => $oldPass,
                'newpass' => $newPass,
            ]);
        } catch (CpanelError $e) {
            $api->sendMessage($chatId, '⚠️ تغییر رمز ناموفق بود: ' . $e->getMessage());

            return;
        }
        if ($profile['auth_type'] === 'password') {
            HostStore::save($chatId, $profile['host'], $profile['port'], $profile['username'], 'password', $newPass);
        }
        $api->sendMessage($chatId, '✅ رمز اکانت cPanel با موفقیت عوض شد.');
    }

    // ---------------------------------------------------------------
    // دستورهای مدیریت هاست
    // ---------------------------------------------------------------

    private static function cmdDisconnect(TelegramApi $api, int $chatId): void
    {
        if (HostStore::get($chatId) === null) {
            $api->sendMessage($chatId, 'در حال حاضر به هیچ هاستی وصل نیستید.');

            return;
        }
        HostStore::remove($chatId);
        $api->sendMessage($chatId, '🔌 اتصال قطع شد و اطلاعات هاست از ربات پاک شد.');
    }

    private static function cmdInfo(TelegramApi $api, int $chatId): void
    {
        $profile = self::requireProfile($api, $chatId);
        if ($profile === null) {
            return;
        }
        $client = CpanelClient::fromProfile($profile);
        $api->sendChatAction($chatId, 'typing');
        try {
            $quota = $client->uapi('Quota', 'get_quota_info');
            $domainsData = $client->uapi('DomainInfo', 'domains_data');
        } catch (CpanelError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage(
            $chatId,
            Render::accountInfo($profile['host'], $profile['port'], $profile['username'], $profile['auth_type'], $quota, $domainsData),
            null,
            'HTML',
        );
    }

    private static function cmdDomains(TelegramApi $api, int $chatId): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        $api->sendChatAction($chatId, 'typing');
        try {
            $data = $client->uapi('DomainInfo', 'domains_data');
        } catch (CpanelError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage($chatId, Render::domains($data), null, 'HTML');
    }

    private static function cmdSubdomain(TelegramApi $api, int $chatId, array $args): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        $sub = strtolower($args[0] ?? '');

        if ($sub === 'add') {
            $name = $args[1] ?? null;
            $domain = $args[2] ?? null;
            $dir = $args[3] ?? null;
            if ($name === null || $domain === null) {
                $api->sendMessage($chatId, "استفاده:\n/subdomain add <نام> <دامنه> [مسیر]\nمثال: /subdomain add blog example.com");

                return;
            }
            $params = ['domain' => $name, 'rootdomain' => $domain];
            if ($dir !== null) {
                $params['dir'] = $dir;
            }
            self::runApi2($api, $chatId, $client, 'SubDomain', 'addsubdomain', $params, "✅ زیردامنه {$name}.{$domain} ساخته شد.");

            return;
        }

        if ($sub === 'del' || $sub === 'delete') {
            $full = $args[1] ?? null;
            if ($full === null) {
                $api->sendMessage($chatId, "استفاده:\n/subdomain del <زیردامنه‌کامل>\nمثال: /subdomain del blog.example.com");

                return;
            }
            self::runApi2($api, $chatId, $client, 'SubDomain', 'delsubdomain', ['domain' => $full], "✅ زیردامنه {$full} حذف شد.");

            return;
        }

        $api->sendMessage($chatId, "استفاده:\n/subdomain add <نام> <دامنه> [مسیر]\n/subdomain del <زیردامنه‌کامل>");
    }

    private static function cmdEmail(TelegramApi $api, int $chatId, ?int $messageId, array $args): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        $sub = strtolower($args[0] ?? 'list');

        if ($sub === 'list') {
            $domain = $args[1] ?? null;
            $api->sendChatAction($chatId, 'typing');
            try {
                $data = $client->uapi('Email', 'list_pops', $domain !== null ? ['domain' => $domain] : []);
            } catch (CpanelError $e) {
                $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

                return;
            }
            $api->sendMessage($chatId, Render::emailList($data, $domain), null, 'HTML');

            return;
        }

        if ($sub === 'add') {
            if ($messageId !== null) {
                $api->deleteMessage($chatId, $messageId);
            }
            $email = $args[1] ?? null;
            $password = $args[2] ?? null;
            $quota = $args[3] ?? '250';
            $split = $email !== null ? self::splitEmail($email) : null;
            if ($split === null || $password === null) {
                $api->sendMessage($chatId, "استفاده:\n/email add <ایمیل> <رمز> [حجم‌مگابایت]\nمثال: /email add info@example.com \"رمزقوی\" 250");

                return;
            }
            self::runUapi($api, $chatId, $client, 'Email', 'add_pop', [
                'domain' => $split[1],
                'email' => $split[0],
                'password' => $password,
                'quota' => $quota,
            ], "✅ ایمیل {$email} ساخته شد.");

            return;
        }

        if ($sub === 'passwd') {
            if ($messageId !== null) {
                $api->deleteMessage($chatId, $messageId);
            }
            $email = $args[1] ?? null;
            $password = $args[2] ?? null;
            $split = $email !== null ? self::splitEmail($email) : null;
            if ($split === null || $password === null) {
                $api->sendMessage($chatId, "استفاده:\n/email passwd <ایمیل> <رمزجدید>");

                return;
            }
            self::runUapi($api, $chatId, $client, 'Email', 'passwd_pop', [
                'domain' => $split[1],
                'email' => $split[0],
                'password' => $password,
            ], "✅ رمز ایمیل {$email} عوض شد.");

            return;
        }

        if ($sub === 'del' || $sub === 'delete') {
            $email = $args[1] ?? null;
            $split = $email !== null ? self::splitEmail($email) : null;
            if ($split === null) {
                $api->sendMessage($chatId, "استفاده:\n/email del <ایمیل>");

                return;
            }
            self::runUapi($api, $chatId, $client, 'Email', 'delete_pop', [
                'domain' => $split[1],
                'email' => $split[0],
            ], "✅ ایمیل {$email} حذف شد.");

            return;
        }

        $api->sendMessage($chatId, "استفاده:\n/email list [دامنه]\n/email add <ایمیل> <رمز> [حجم‌مگابایت]\n/email passwd <ایمیل> <رمزجدید>\n/email del <ایمیل>");
    }

    private static function cmdDb(TelegramApi $api, int $chatId, ?int $messageId, array $args): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        $sub = strtolower($args[0] ?? 'list');

        if ($sub === 'list') {
            $api->sendChatAction($chatId, 'typing');
            try {
                $databases = $client->uapi('Mysql', 'list_databases');
                $users = $client->uapi('Mysql', 'list_users');
            } catch (CpanelError $e) {
                $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

                return;
            }
            $api->sendMessage($chatId, Render::dbOverview($databases, $users), null, 'HTML');

            return;
        }

        if ($sub === 'create') {
            $name = $args[1] ?? null;
            if ($name === null) {
                $api->sendMessage($chatId, "استفاده:\n/db create <نام>");

                return;
            }
            self::runUapi($api, $chatId, $client, 'Mysql', 'create_database', ['name' => $name], "✅ دیتابیس {$name} ساخته شد.");

            return;
        }

        if ($sub === 'delete') {
            $name = $args[1] ?? null;
            if ($name === null) {
                $api->sendMessage($chatId, "استفاده:\n/db delete <نام>");

                return;
            }
            self::runUapi($api, $chatId, $client, 'Mysql', 'delete_database', ['name' => $name], "✅ دیتابیس {$name} حذف شد.");

            return;
        }

        if ($sub === 'user') {
            $action = strtolower($args[1] ?? '');
            if ($action === 'add') {
                if ($messageId !== null) {
                    $api->deleteMessage($chatId, $messageId);
                }
                $user = $args[2] ?? null;
                $pass = $args[3] ?? null;
                if ($user === null || $pass === null) {
                    $api->sendMessage($chatId, "استفاده:\n/db user add <یوزر> <رمز>");

                    return;
                }
                self::runUapi($api, $chatId, $client, 'Mysql', 'create_user', ['name' => $user, 'password' => $pass], "✅ یوزر {$user} ساخته شد.");

                return;
            }
            if ($action === 'del' || $action === 'delete') {
                $user = $args[2] ?? null;
                if ($user === null) {
                    $api->sendMessage($chatId, "استفاده:\n/db user del <یوزر>");

                    return;
                }
                self::runUapi($api, $chatId, $client, 'Mysql', 'delete_user', ['name' => $user], "✅ یوزر {$user} حذف شد.");

                return;
            }
            $api->sendMessage($chatId, "استفاده:\n/db user add <یوزر> <رمز>\n/db user del <یوزر>");

            return;
        }

        if ($sub === 'grant') {
            $user = $args[1] ?? null;
            $db = $args[2] ?? null;
            $privileges = $args[3] ?? 'ALL PRIVILEGES';
            if ($user === null || $db === null) {
                $api->sendMessage($chatId, "استفاده:\n/db grant <یوزر> <دیتابیس> [دسترسی]\nمثال: /db grant shopuser shopdb");

                return;
            }
            self::runUapi($api, $chatId, $client, 'Mysql', 'set_privileges_on_database', [
                'user' => $user,
                'database' => $db,
                'privileges' => $privileges,
            ], "✅ دسترسی «{$privileges}» برای {$user} روی {$db} ثبت شد.");

            return;
        }

        $api->sendMessage($chatId, "استفاده:\n/db list\n/db create <نام>\n/db delete <نام>\n/db user add <یوزر> <رمز>\n/db user del <یوزر>\n/db grant <یوزر> <دیتابیس> [دسترسی]");
    }

    private static function cmdFtp(TelegramApi $api, int $chatId, ?int $messageId, array $args): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        $sub = strtolower($args[0] ?? 'list');

        if ($sub === 'list') {
            $api->sendChatAction($chatId, 'typing');
            try {
                $data = $client->uapi('Ftp', 'list_ftp');
            } catch (CpanelError $e) {
                $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

                return;
            }
            $api->sendMessage($chatId, Render::ftpList($data), null, 'HTML');

            return;
        }

        if ($sub === 'add') {
            if ($messageId !== null) {
                $api->deleteMessage($chatId, $messageId);
            }
            $user = $args[1] ?? null;
            $pass = $args[2] ?? null;
            $quota = $args[3] ?? '0';
            if ($user === null || $pass === null) {
                $api->sendMessage($chatId, "استفاده:\n/ftp add <یوزر> <رمز> [حجم‌مگابایت=0 یعنی نامحدود]");

                return;
            }
            self::runUapi($api, $chatId, $client, 'Ftp', 'add_ftp', [
                'user' => $user,
                'pass' => $pass,
                'quota' => $quota,
            ], "✅ اکانت FTP {$user} ساخته شد.");

            return;
        }

        if ($sub === 'del' || $sub === 'delete') {
            $user = $args[1] ?? null;
            if ($user === null) {
                $api->sendMessage($chatId, "استفاده:\n/ftp del <یوزر>");

                return;
            }
            self::runUapi($api, $chatId, $client, 'Ftp', 'delete_ftp', ['user' => $user], "✅ اکانت FTP {$user} حذف شد.");

            return;
        }

        $api->sendMessage($chatId, "استفاده:\n/ftp list\n/ftp add <یوزر> <رمز> [حجم‌مگابایت]\n/ftp del <یوزر>");
    }

    private static function cmdDns(TelegramApi $api, int $chatId, array $args): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        $sub = strtolower($args[0] ?? '');

        if ($sub === 'list') {
            $domain = $args[1] ?? null;
            if ($domain === null) {
                $api->sendMessage($chatId, "استفاده:\n/dns list <دامنه>");

                return;
            }
            $api->sendChatAction($chatId, 'typing');
            try {
                $data = $client->api2('ZoneEdit', 'fetchzone', ['domain' => $domain]);
            } catch (CpanelError $e) {
                $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

                return;
            }
            $records = $data[0]['record'] ?? $data;
            $api->sendMessage($chatId, Render::dnsZone(is_array($records) ? $records : [], $domain), null, 'HTML');

            return;
        }

        if ($sub === 'add') {
            $domain = $args[1] ?? null;
            $name = $args[2] ?? null;
            $type = strtoupper($args[3] ?? '');
            $value = $args[4] ?? null;
            $ttl = $args[5] ?? '14400';
            if ($domain === null || $name === null || $type === '' || $value === null) {
                $api->sendMessage($chatId, "استفاده:\n/dns add <دامنه> <نام> <نوع> <مقدار> [ttl]\nمثال: /dns add example.com www A 1.2.3.4\nبرای MX: /dns add example.com @ MX \"10 mail.example.com\" 14400");

                return;
            }
            $params = ['domain' => $domain, 'name' => $name, 'type' => $type, 'ttl' => $ttl];
            switch ($type) {
                case 'A':
                case 'AAAA':
                    $params['address'] = $value;

                    break;
                case 'CNAME':
                    $params['cname'] = $value;

                    break;
                case 'TXT':
                    $params['txtdata'] = $value;

                    break;
                case 'NS':
                    $params['nsdname'] = $value;

                    break;
                case 'MX':
                    $parts = preg_split('/\s+/', trim($value), 2);
                    $params['preference'] = $parts[0] ?? '10';
                    $params['exchange'] = $parts[1] ?? $value;

                    break;
                default:
                    $params['value'] = $value;
            }
            self::runApi2($api, $chatId, $client, 'ZoneEdit', 'add_zone_record', $params, "✅ رکورد {$type} برای {$name}.{$domain} اضافه شد.");

            return;
        }

        if ($sub === 'del' || $sub === 'delete') {
            $domain = $args[1] ?? null;
            $line = $args[2] ?? null;
            if ($domain === null || $line === null) {
                $api->sendMessage($chatId, "استفاده:\n/dns del <دامنه> <شماره‌خط>\n(شماره‌خط رو از /dns list ببینید)");

                return;
            }
            self::runApi2($api, $chatId, $client, 'ZoneEdit', 'remove_zone_record', ['domain' => $domain, 'line' => $line], '✅ رکورد حذف شد.');

            return;
        }

        $api->sendMessage($chatId, "استفاده:\n/dns list <دامنه>\n/dns add <دامنه> <نام> <نوع> <مقدار> [ttl]\n/dns del <دامنه> <شماره‌خط>");
    }

    private static function cmdRaw(TelegramApi $api, int $chatId, array $args): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        $module = $args[0] ?? null;
        $function = $args[1] ?? null;
        if ($module === null || $function === null) {
            $api->sendMessage($chatId, "استفاده (پیشرفته):\n/raw <ماژول> <تابع> [کلید=مقدار ...]\nمثال: /raw Mysql list_databases\nبرای cPanel API 2 قدیمی: /raw api2:ZoneEdit fetchzone domain=example.com");

            return;
        }
        $params = [];
        foreach (array_slice($args, 2) as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $params[$key] = $value;
            }
        }

        $api->sendChatAction($chatId, 'typing');
        try {
            if (str_starts_with($module, 'api2:')) {
                $data = $client->api2(substr($module, strlen('api2:')), $function, $params);
            } else {
                $data = $client->uapi($module, $function, $params);
            }
        } catch (CpanelError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage($chatId, Render::raw($data), null, 'HTML');
    }

    private static function cmdFiles(TelegramApi $api, int $chatId, array $args): void
    {
        $client = self::requireClient($api, $chatId);
        if ($client === null) {
            return;
        }
        $sub = strtolower($args[0] ?? '');

        if ($sub === 'list' || $sub === '') {
            $dir = $args[1] ?? '.';
            $api->sendChatAction($chatId, 'typing');
            try {
                $data = $client->uapi('Fileman', 'list_files', ['dir' => $dir]);
            } catch (CpanelError $e) {
                $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

                return;
            }
            $api->sendMessage($chatId, Render::fileList($data, $dir), null, 'HTML');

            return;
        }

        if ($sub === 'view') {
            $path = $args[1] ?? null;
            if ($path === null) {
                $api->sendMessage($chatId, "استفاده:\n/files view <مسیر>\nمثال: /files view public_html/index.html");

                return;
            }
            [$dir, $file] = self::splitPath($path);
            $api->sendChatAction($chatId, 'typing');
            try {
                $data = $client->uapi('Fileman', 'get_file_content', ['dir' => $dir, 'file' => $file]);
            } catch (CpanelError $e) {
                $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

                return;
            }
            $api->sendMessage($chatId, Render::fileContent($data, $path), null, 'HTML');

            return;
        }

        if ($sub === 'write') {
            $path = $args[1] ?? null;
            if ($path === null) {
                $api->sendMessage($chatId, "استفاده:\n/files write <مسیر>\nبعدش محتوای کامل فایل رو در پیام بعدی بفرستید (کل فایل جایگزین می‌شه). برای لغو: /cancel");

                return;
            }
            ConversationState::set($chatId, 'files_write_content', ['path' => $path]);
            $api->sendMessage($chatId, "📝 محتوای کامل جدید برای «{$path}» رو در یک پیام بفرستید (محتوای فعلی فایل بازنویسی می‌شه). برای لغو: /cancel");

            return;
        }

        if ($sub === 'mkdir') {
            $path = $args[1] ?? null;
            $name = $args[2] ?? null;
            if ($path === null || $name === null) {
                $api->sendMessage($chatId, "استفاده:\n/files mkdir <مسیر-والد> <نام-پوشه>\nمثال: /files mkdir public_html backups");

                return;
            }
            self::runApi2($api, $chatId, $client, 'Fileman', 'mkdir', ['path' => $path, 'name' => $name], "✅ پوشه‌ی {$name} ساخته شد.");

            return;
        }

        if ($sub === 'del' || $sub === 'delete') {
            $path = $args[1] ?? null;
            if ($path === null) {
                $api->sendMessage($chatId, "استفاده:\n/files del <مسیر>\n⚠️ این عملیات قابل بازگشت نیست.");

                return;
            }
            self::runApi2($api, $chatId, $client, 'Fileman', 'fileop', ['op' => 'unlink', 'sourcefiles' => $path], "✅ {$path} حذف شد.");

            return;
        }

        if ($sub === 'rename' || $sub === 'move') {
            $from = $args[1] ?? null;
            $to = $args[2] ?? null;
            if ($from === null || $to === null) {
                $api->sendMessage($chatId, "استفاده:\n/files rename <مسیر-قبلی> <مسیر-جدید>");

                return;
            }
            self::runApi2($api, $chatId, $client, 'Fileman', 'fileop', ['op' => 'rename', 'sourcefiles' => $from, 'destfiles' => $to], "✅ به {$to} تغییر نام یافت.");

            return;
        }

        if ($sub === 'copy') {
            $from = $args[1] ?? null;
            $to = $args[2] ?? null;
            if ($from === null || $to === null) {
                $api->sendMessage($chatId, "استفاده:\n/files copy <مبدأ> <مقصد>");

                return;
            }
            self::runApi2($api, $chatId, $client, 'Fileman', 'fileop', ['op' => 'copy', 'sourcefiles' => $from, 'destfiles' => $to], "✅ در {$to} کپی شد.");

            return;
        }

        $api->sendMessage($chatId, "استفاده:\n/files list [مسیر]\n/files view <مسیر>\n/files write <مسیر>\n/files mkdir <مسیر-والد> <نام>\n/files rename <قبلی> <جدید>\n/files copy <مبدأ> <مقصد>\n/files del <مسیر>");
    }

    private static function cmdSshPort(TelegramApi $api, int $chatId, array $args): void
    {
        $profile = self::requireProfile($api, $chatId);
        if ($profile === null) {
            return;
        }
        $portArg = $args[0] ?? null;
        if ($portArg === null || !ctype_digit($portArg) || (int) $portArg < 1 || (int) $portArg > 65535) {
            $api->sendMessage($chatId, "استفاده:\n/sshport <پورت>\nمثال: /sshport 22\n(پیش‌فرض 22 هست؛ فقط اگه هاست‌تون پورت SSH غیراستاندارد داره لازمه عوضش کنید.)");

            return;
        }
        HostStore::save($chatId, $profile['host'], $profile['port'], $profile['username'], $profile['auth_type'], $profile['secret'], (int) $portArg);
        $api->sendMessage($chatId, "✅ پورت SSH روی {$portArg} تنظیم شد.");
    }

    private static function cmdSsh(TelegramApi $api, int $chatId, string $fullText): void
    {
        $profile = self::requireProfile($api, $chatId);
        if ($profile === null) {
            return;
        }
        if ($profile['auth_type'] !== 'password') {
            $api->sendMessage($chatId, "❗️ /ssh فقط با اتصال «رمز عبور» کار می‌کنه (API Token برای ورود SSH معتبر نیست). با /connect دوباره با رمز عبور وصل شید.");

            return;
        }
        $command = self::rawArgAfter($fullText);
        if ($command === '') {
            $api->sendMessage($chatId, "استفاده:\n/ssh <دستور>\nمثال: /ssh df -h\n\n⚠️ این یعنی اجرای واقعی دستور شل روی سرورتون — دقت کنید چی می‌فرستید. نیاز به فعال‌بودن SSH روی هاست داره.");

            return;
        }
        $api->sendChatAction($chatId, 'typing');
        $ssh = new SshClient($profile['host'], $profile['ssh_port'], $profile['username'], $profile['secret']);
        try {
            $result = $ssh->exec($command);
        } catch (SshError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage($chatId, Render::sshResult($result, $command), null, 'HTML');
    }

    // ---------------------------------------------------------------
    // ابزارهای کمکی
    // ---------------------------------------------------------------

    private static function runUapi(TelegramApi $api, int $chatId, CpanelClient $client, string $module, string $function, array $params, string $successMessage): void
    {
        $api->sendChatAction($chatId, 'typing');
        try {
            $client->uapi($module, $function, $params);
        } catch (CpanelError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage($chatId, $successMessage);
    }

    private static function runApi2(TelegramApi $api, int $chatId, CpanelClient $client, string $module, string $function, array $params, string $successMessage): void
    {
        $api->sendChatAction($chatId, 'typing');
        try {
            $client->api2($module, $function, $params);
        } catch (CpanelError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage($chatId, $successMessage);
    }

    /**
     * @return array{host:string,port:int,username:string,auth_type:string,secret:string}|null
     */
    private static function requireProfile(TelegramApi $api, int $chatId): ?array
    {
        $profile = HostStore::get($chatId);
        if ($profile === null) {
            $api->sendMessage($chatId, "❗️ ابتدا با /connect هاست‌تون رو وصل کنید.");

            return null;
        }

        return $profile;
    }

    private static function requireClient(TelegramApi $api, int $chatId): ?CpanelClient
    {
        $profile = self::requireProfile($api, $chatId);

        return $profile === null ? null : CpanelClient::fromProfile($profile);
    }

    /**
     * @return array{0:string,1:string}|null [local-part, domain]
     */
    private static function splitEmail(string $email): ?array
    {
        if (!str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') {
            return null;
        }

        return [$local, $domain];
    }

    private static function tokenize(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        $parts = str_getcsv(trim($text), ' ', '"', '\\');

        return array_values(array_filter($parts, static fn ($p) => $p !== null && $p !== ''));
    }

    private static function argsAfter(string $text): array
    {
        $pos = strpos($text, ' ');
        $rest = $pos === false ? '' : trim(substr($text, $pos + 1));

        return self::tokenize($rest);
    }

    /**
     * برخلاف argsAfter، اینجا بقیه‌ی پیام رو دست‌نخورده (بدون توکنایز/کوت‌زدایی)
     * برمی‌گردونه — برای /ssh لازمه چون دستور شل ممکنه quote، pipe، redirect
     * و... داشته باشه که نباید توسط پارسر ما دست‌کاری بشه.
     */
    private static function rawArgAfter(string $text): string
    {
        $pos = strpos($text, ' ');

        return $pos === false ? '' : trim(substr($text, $pos + 1));
    }

    /**
     * برای Fileman::get_file_content که dir و file رو جدا می‌خواد.
     *
     * @return array{0:string,1:string} [dir, file]
     */
    private static function splitPath(string $path): array
    {
        $path = trim($path, '/');
        $dir = dirname($path);
        $file = basename($path);

        return [$dir === '' ? '.' : $dir, $file];
    }

    private static function handlePendingEdit(TelegramApi $api, int $chatId, string $text): bool
    {
        $awaiting = EditState::get();
        if ($awaiting === null) {
            return false;
        }

        if ($text === '/cancel') {
            EditState::clear();
            $api->sendMessage($chatId, 'ویرایش لغو شد.');

            return true;
        }

        if (str_starts_with($text, '/')) {
            EditState::clear();

            return false;
        }

        TextStore::set($awaiting, $text);
        EditState::clear();
        $label = TextStore::LABELS[$awaiting] ?? $awaiting;
        $api->sendMessage($chatId, "✅ «{$label}» ذخیره شد.");

        return true;
    }

    private static function buildStatusText(): string
    {
        $tehran = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));

        return "🟢 <b>ربات فعال است و آماده‌ی پاسخگویی</b>\n"
            . "🕐 ساعت تهران: {$tehran->format('H:i:s')}\n"
            . '🐘 PHP ' . PHP_VERSION;
    }

    private static function sendMainMenu(TelegramApi $api, int $chatId): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔌 اتصال هاست', 'callback_data' => 'connect'], ['text' => '🟢 وضعیت ربات', 'callback_data' => 'status']],
                [['text' => '🌐 دامنه‌ها', 'callback_data' => 'domains'], ['text' => '📧 ایمیل', 'callback_data' => 'email']],
                [['text' => '🗄 دیتابیس', 'callback_data' => 'db'], ['text' => '📁 FTP', 'callback_data' => 'ftp']],
                [['text' => '🧭 DNS', 'callback_data' => 'dns'], ['text' => '🔑 تغییر رمز', 'callback_data' => 'password']],
                [['text' => '🗂 فایل‌منیجر', 'callback_data' => 'files'], ['text' => '💻 ترمینال SSH', 'callback_data' => 'ssh']],
            ],
        ];
        $api->sendMessage($chatId, TextStore::get(TextStore::KEY_WELCOME), $keyboard);
    }

    private static function sendAdminPanel(TelegramApi $api, int $chatId): void
    {
        $isPublic = Settings::isPublic();
        $toggleLabel = $isPublic ? '🌐 حالت عمومی: روشن (بزنید تا فقط ادمین بشه)' : '🔒 حالت عمومی: خاموش (بزنید تا برای همه باز بشه)';

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🟢 وضعیت ربات', 'callback_data' => 'status']],
                [['text' => $toggleLabel, 'callback_data' => 'toggle_public']],
                [['text' => '✏️ ویرایش پیام خوش‌آمد', 'callback_data' => 'edit_welcome']],
                [['text' => '❓ راهنما', 'callback_data' => 'help']],
            ],
        ];
        $publicNote = $isPublic
            ? 'حالت عمومی روشنه — همه می‌تونن هاست خودشون رو وصل کنن و مدیریت کنن.'
            : 'حالت عمومی خاموشه — فقط شما به قابلیت‌های ربات دسترسی دارید.';
        $text = "🛠 <b>پنل مدیریت ربات</b>\n\n"
            . "$publicNote\n\n"
            . 'برای مدیریت هاست خودتون کافیه /connect بزنید.';
        $api->sendMessage($chatId, $text, $keyboard, 'HTML');
    }

    private static function isCommand(string $text, string $name): bool
    {
        return $text === $name || str_starts_with($text, $name . ' ') || str_starts_with($text, $name . '@');
    }
}
