<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Landing;

use \Airship\Alerts\Security\UserNotFound;
use \Airship\Cabin\Bridge\Blueprint\UserAccounts;
use \Airship\Engine\{
    AutoPilot,
    Bolt\Security,
    Gears,
    State
};
use \Airship\Engine\Security\{
    AirBrake,
    Filter\BoolFilter,
    Filter\GeneralFilterContainer,
    Filter\StringFilter,
    HiddenString,
    Util
};
use \ParagonIE\ConstantTime\Base64UrlSafe;
use \ParagonIE\GPGMailer\GPGMailer;
use \ParagonIE\Halite\{
    Alerts\InvalidMessage,
    Asymmetric\Crypto as Asymmetric,
    Util as CryptoUtil
};
use \ParagonIE\MultiFactor\OTP\TOTP;
use \ParagonIE\MultiFactor\Vendor\GoogleAuth;
use \Psr\Log\LogLevel;
use \Zend\Mail\{
    Exception\InvalidArgumentException,
    Message,
    Transport\Sendmail
};

require_once __DIR__.'/init_gear.php';

/**
 * Class Account
 *
 * Landing for user account stuff. Also contains the login and registration forms.
 *
 * @package Airship\Cabin\Bridge\Landing
 */
class Account extends LandingGear
{
    use Security;

    /**
     * @var UserAccounts
     */
    protected $acct;

    /**
     * This function is called after the dependencies have been injected by
     * AutoPilot. Think of it as a user-land constructor.
     */
    public function airshipLand()
    {
        parent::airshipLand();
        $this->acct = $this->blueprint('UserAccounts');
    }

    /**
     * Process the /board API endpoint.
     *
     * @route board
     */
    public function board()
    {
        if ($this->isLoggedIn())  {
            // You're already logged in!
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->config('board.enabled')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }

        $post = $this->post($this->getBoardFilterContainer());
        if (!empty($post)) {
            // Optional: CAPTCHA enforcement
            if ($this->config('board.captcha')) {
                if (isset($post['g-recaptcha-response'])) {
                    $rc = \Airship\getReCaptcha(
                        $this->config('recaptcha.secret-key'),
                        $this->config('recaptcha.curl-opts') ?? []
                    );
                    $resp = $rc->verify(
                        $post['g-recaptcha-response'],
                        $_SERVER['REMOTE_ADDR']
                    );
                    if ($resp->isSuccess()) {
                        $this->processBoard($post);
                        return;
                    } else {
                        $this->lens('board', [
                            'config' => $this->config(),
                            'title' => 'All Aboard!'
                        ]);
                    }
                }
            } else {
                $this->processBoard($post);
                return;
            }
        }
        $this->lens('board', [
            'config' => $this->config(),
            'title' => 'All Aboard!'
        ]);
    }

    /**
     * Handle login requests
     *
     * @route login
     */
    public function login()
    {
        if ($this->isLoggedIn())  {
            // You're already logged in!
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $post = $this->post($this->getLoginFilterContainer());
        if (!empty($post)) {
            $this->processLogin($post);
            return;
        }
        $this->lens('login');
    }

    /**
     * CSRF-resistant logout script
     *
     * @route logout/(.*)
     * @param string $token
     * @return mixed
     */
    public function logout(string $token)
    {
        if (!$this->isLoggedIn())  {
            \Airship\redirect($this->airship_cabin_prefix);
        }

        $state = State::instance();
        $idx = $state->universal['session_index']['logout_token'];
        if (\array_key_exists($idx, $_SESSION)) {
            if (\hash_equals($token, $_SESSION[$idx])) {
                $this->completeLogOut();
            }
        }
        \Airship\redirect($this->airship_cabin_prefix);
    }

    /**
     * @route my/account
     */
    public function my()
    {
        if (!$this->isLoggedIn())  {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $account = $this->acct->getUserAccount($this->getActiveUserId());
        $gpg_public_key = '';
        if (!empty($account['gpg_public_key'])) {
            $gpg_public_key = $this->getGPGPublicKey($account['gpg_public_key']);
        }
        $post = $this->post($this->getMyAccountFilterContainer());
        if (!empty($post)) {
            $this->processAccountUpdate($post, $account, $gpg_public_key);
            return;
        }
        $this->lens(
            'my_account',
            [
                'account' => $account,
                'gpg_public_key' => $gpg_public_key
            ]
        );
    }

    /**
     * @route my
     */
    public function myIndex()
    {
        \Airship\redirect($this->airship_cabin_prefix . '/my/account');
    }

    /**
     * Allows users to select which Motif to use
     *
     * @route my/preferences
     */
    public function preferences()
    {
        if (!$this->isLoggedIn())  {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $prefs = $this->acct->getUserPreferences(
            $this->getActiveUserId()
        );
        $cabins = [];
        $motifs = [];
        foreach ($this->getCabinNamespaces() as $cabin) {
            $cabins[] = $cabin;
            $filename = ROOT . '/tmp/cache/' . $cabin . '.motifs.json';
            if (\file_exists($filename)) {
                $motifs[$cabin] = \Airship\loadJSON($filename);
            } else {
                $motifs[$cabin] = [];
            }

        }

        $filters = $this->getPreferencesFilterContainer(
            $cabins,
            $motifs
        );
        $post = $this->post($filters);
        if (!empty($post)) {
            if ($this->savePreferences($post['prefs'], $cabins, $motifs)) {
                $prefs = $post['prefs'];
            }
        }

        $this->lens('preferences', [
            'prefs' =>
                $prefs,
            'motifs' =>
                $motifs
        ]);
    }

    /**
     * A directory of public users
     *
     * @param string $page
     * @route users{_page}
     */
    public function publicDirectory(string $page = '')
    {
        list($offset, $limit) = $this->getOffsetAndLimit($page);
        $directory = $this->acct->getDirectory($offset, $limit);
        $this->lens(
            'user_directory',
            [
                'directory' => $directory,
                'pagination' => [
                    'base' => $this->airship_cabin_prefix . '/users',
                    'suffix' => '/',
                    'count' => $this->acct->countPublicUsers(),
                    'page' => (int) \ceil($offset / ($limit ?? 1)) + 1,
                    'per_page' => $limit
                ]
            ]
        );
    }

    /**
     * @route recover-account
     * @param string $token
     */
    public function recoverAccount(string $token = '')
    {
        if ($this->isLoggedIn())  {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $enabled = $this->config('password-reset.enabled');
        if (empty($enabled)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $post = $this->post($this->getAccountRecoveryFilterContainer());
        if ($post) {
            if ($this->processRecoverAccount($post)) {
                \Airship\redirect($this->airship_cabin_prefix . '/login');
            } else {
                $this->storeLensVar(
                    'form_message',
                    \__("User doesn't exist or opted out of account recovery.")
                );
            }
        }
        if (!empty($token)) {
            $this->processRecoveryToken($token);
        }
        $this->lens('recover_account');
    }

    /**
     * Returns the user's QR code.
     * @route my/account/2-factor/qr-code
     *
     */
    public function twoFactorSetupQRCode()
    {
        if (!$this->isLoggedIn())  {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $gauth = $this->twoFactorPreamble();
        $user = $this->acct->getUserAccount($this->getActiveUserId());

        \header('Content-Type: image/png');
        $gauth->makeQRCode(
            null,
            'php://output',
            $user['username'] . '@' . $_SERVER['HTTP_HOST'],
            $this->config('two-factor.issuer') ?? '',
            $this->config('two-factor.label') ?? ''
        );
    }

    /**
     * @route my/account/2-factor
     */
    public function twoFactorSetup()
    {
        if (!$this->isLoggedIn())  {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $this->twoFactorPreamble();
        $userID = $this->getActiveUserId();
        $post = $this->post($this->getTwoFactorFilterContainer());
        if ($post) {
            $this->acct->toggleTwoFactor($userID, $post);
        }
        $user = $this->acct->getUserAccount($userID);
        
        $this->lens(
            'two_factor',
            [
                'enabled' => $user['enable_2factor'] ?? false
            ]
        );
    }

    /**
     * Is this motif part of this cabin?
     *
     * @param array $motifs
     * @param string $supplier
     * @param string $motifName
     * @return bool
     */
    protected function findMotif(
        array $motifs,
        string $supplier,
        string $motifName
    ): bool {
        foreach ($motifs as $id => $data) {
            if (
                $data['config']['supplier'] === $supplier
                    &&
                $data['config']['name'] === $motifName
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return GeneralFilterContainer
     */
    protected function getAccountRecoveryFilterContainer(): GeneralFilterContainer
    {
        return (new GeneralFilterContainer())
            ->addFilter(
                'forgot_passphrase_for',
                (new StringFilter())
                    ->addCallback(function ($string) {
                        if (Util::stringLength($string) < 1) {
                            throw new \TypeError();
                        }
                    })
            );
    }

    /**
     * Get the input filter container for registering
     *
     * @return GeneralFilterContainer
     */
    protected function getBoardFilterContainer(): GeneralFilterContainer
    {
        return (new GeneralFilterContainer())
            ->addFilter(
                'username',
                (new StringFilter())
                    ->addCallback(function ($string) {
                        if (Util::stringLength($string) < 1) {
                            throw new \TypeError();
                        }
                    })
            )
            ->addFilter(
                'passphrase',
                (new StringFilter())
                    ->addCallback(function ($string) {
                        if (Util::stringLength($string) < 1) {
                            throw new \TypeError();
                        }
                    })
            );
    }

    /**
     * Return the public key corresponding to a fingerprint
     *
     * @param string $fingerprint
     * @return string
     */
    protected function getGPGPublicKey(string $fingerprint): string
    {
        $state = State::instance();
        try {
            return \trim(
                $state->gpgMailer->export($fingerprint)
            );
        } catch (\Crypt_GPG_Exception $ex) {
            return '';
        }
    }


    /**
     * @return GeneralFilterContainer
     */
    protected function getLoginFilterContainer(): GeneralFilterContainer
    {
        return (new GeneralFilterContainer())
            ->addFilter(
                'username',
                (new StringFilter())
                    ->addCallback(function ($string) {
                        if (Util::stringLength($string) < 1) {
                            throw new \TypeError();
                        }
                    })
            )
            ->addFilter(
                'passphrase',
                (new StringFilter())
                    ->addCallback(function ($string) {
                        if (Util::stringLength($string) < 1) {
                            throw new \TypeError();
                        }
                    })
            )
            ->addFilter(
                'two_factor',
                (new StringFilter())
                    ->addCallback(function ($string) {
                        if (Util::stringLength($string) < 6) {
                            throw new \TypeError();
                        } elseif (Util::stringLength($string) > 8) {
                            throw new \TypeError();
                        }
                    })
            );
    }

    /**
     * @return GeneralFilterContainer
     */
    protected function getMyAccountFilterContainer(): GeneralFilterContainer
    {
        return (new GeneralFilterContainer())
            ->addFilter('allow_reset', new BoolFilter())
            ->addFilter('display_name', new StringFilter())
            ->addFilter('email', new StringFilter())
            ->addFilter('gpg_public_key', new StringFilter())
            ->addFilter('passphrase', new StringFilter())
            ->addFilter('publicprofile', new BoolFilter())
            ->addFilter('real_name', new StringFilter());
    }

    /**
     * Get the filter container for the Preferences form
     *
     * @param string[] $cabinNamespaces
     * @param array $motifs
     * @return GeneralFilterContainer
     */
    protected function getPreferencesFilterContainer(
        array $cabinNamespaces = [],
        array $motifs = []
    ): GeneralFilterContainer {
        $filterContainer = new GeneralFilterContainer();
        foreach ($cabinNamespaces as $cabin) {
            $activeCabin = $motifs[$cabin];
            $filterContainer->addFilter(
                'prefs.motif.' . $cabin,
                (new StringFilter())->addCallback(
                    function ($selected) use ($cabin, $activeCabin): string {
                        foreach ($activeCabin as $cabinConfig) {
                            if ($selected === $cabinConfig['path']) {
                                return $selected;
                            }
                        }
                        return '';
                    }
                )
            );
        }
        return $filterContainer;
    }

    /**
     * @return GeneralFilterContainer
     */
    protected function getTwoFactorFilterContainer(): GeneralFilterContainer
    {
        return (new GeneralFilterContainer())
            ->addFilter('enable_2factor', new BoolFilter())
            ->addFilter('reset_secret', new BoolFilter())
        ;
    }

    /**
     * Process a user account update
     *
     * @param array $post
     * @param array $account
     * @param string $gpg_public_key
     */
    protected function processAccountUpdate(
        array $post = [],
        array $account = [],
        string $gpg_public_key = ''
    ) {
        $state = State::instance();
        $idx = $state->universal['session_index']['user_id'];

        if (!empty($post['passphrase'])) {
            // Lazy hack
            $post['username'] = $account['username'];
            if ($this->acct->isPasswordWeak($post)) {
                $this->lens(
                    'my_account',
                    [
                        'account' => $account,
                        'gpg_public_key' => $gpg_public_key,
                        'post_response' => [
                            'message' => \__('Supplied password is too weak.'),
                            'status' => 'error'
                        ]
                    ]
                );
            }

            // Log password changes as a WARNING
            $this->log(
                'Changing password for user, ' . $account['username'],
                LogLevel::WARNING
            );
            $this->acct->setPassphrase(new HiddenString($post['passphrase']), $_SESSION[$idx]);
            if ($this->config('password-reset.logout')) {
                $this->acct->invalidateLongTermAuthTokens($_SESSION[$idx]);

                // We're not logging ourselves out!
                $_SESSION['session_canary'] = $this->acct->createSessionCanary($_SESSION[$idx]);
            }
            unset($post['username'], $post['passphrase']);
        }

        if ($this->acct->updateAccountInfo($post, $account)) {
            // Refresh:
            $account = $this->acct->getUserAccount($this->getActiveUserId());
            $gpg_public_key = $this->getGPGPublicKey($account['gpg_public_key']);
            $this->lens(
                'my_account',
                [
                    'account' => $account,
                    'gpg_public_key' => $gpg_public_key,
                    'post_response' => [
                        'message' => \__('Account was saved successfully.'),
                        'status' => 'success'
                    ]
                ]
            );
        }
        $this->lens(
            'my_account',
            [
                'account' => $post,
                'gpg_public_key' => $gpg_public_key,
                'post_response' => [
                    'message' => \__('Account was not saved successfully.'),
                    'status' => 'error'
                ]
            ]
        );
    }
    
    /**
     * Process a user account registration request
     *
     * @param array $post
     */
    protected function processBoard(array $post = [])
    {
        $state = State::instance();

        if (empty($post['username']) || empty($post['passphrase'])) {
            $this->lens(
                'board',
                [
                    'post_response' => [
                        'message' => \__('Please fill out the form entirely'),
                        'status' => 'error'
                    ]
                ]
            );
        }

        if ($this->acct->isUsernameTaken($post['username'])) {
            $this->lens(
                'board',
                [
                    'post_response' => [
                        'message' => \__('Username is not available'),
                        'status' => 'error'
                    ]
                ]
            );
        }

        if ($this->acct->isPasswordWeak($post)) {
            $this->lens(
                'board',
                [
                    'post_response' => [
                        'message' => \__('Supplied password is too weak.'),
                        'status' => 'error'
                    ]
                ]
            );
        }

        $userID = $this->acct->createUser($post);
        $idx = $state->universal['session_index']['user_id'];
        $_SESSION[$idx] = (int) $userID;

        \Airship\redirect($this->airship_cabin_prefix);
    }
    
    /**
     * Handle user authentication
     *
     * @param array $post
     */
    protected function processLogin(array $post = [])
    {
        $state = State::instance();

        if (empty($post['username']) || empty($post['passphrase'])) {
            $this->lens('login', [
                'post_response' => [
                    'message' => \__('Please fill out the form entirely'),
                    'status' => 'error'
                ]
            ]);
        }

        $airBrake = Gears::get('AirBrake');
        if (IDE_HACKS) {
            $airBrake = new AirBrake();
        }
        if ($airBrake->failFast($post['username'], $_SERVER['REMOTE_ADDR'])) {
            $this->lens('login', [
                'post_response' => [
                    'message' => \__('You are doing that too fast. Please wait a few seconds and try again.'),
                    'status' => 'error'
                ]
            ]);
        } elseif (!$airBrake->getFastExit()) {
            $delay = $airBrake->getDelay($post['username'], $_SERVER['REMOTE_ADDR']);
            if ($delay > 0) {
                \usleep($delay * 1000);
            }
        }

        try {
            $userID = $this->airship_auth->login(
                $post['username'],
                new HiddenString($post['passphrase'])
            );
        } catch (InvalidMessage $e) {
            $this->log(
                'InvalidMessage Exception on Login; probable cause: password column was corrupted',
                LogLevel::CRITICAL,
                [
                    'exception' => \Airship\throwableToArray($e)
                ]
            );
            $this->lens('login', [
                'post_response' => [
                    'message' => \__('Incorrect username or passphrase. Please try again.'),
                    'status' => 'error'
                ]
            ]);
        }

        if (!empty($userID)) {
            $userID = (int) $userID;
            $user = $this->acct->getUserAccount($userID);
            if ($user['enable_2factor']) {
                if (empty($post['two_factor'])) {
                    $post['two_factor'] = '';
                }
                $gauth = $this->twoFactorPreamble($userID);
                $checked = $gauth->validateCode($post['two_factor'], \time());
                if (!$checked) {
                    $fails = $airBrake->getFailedLoginAttempts(
                        $post['username'],
                        $_SERVER['REMOTE_ADDR']
                    ) + 1;

                    // Instead of the password, seal a timestamped and
                    // signed message saying the password was correct.
                    // We use a signature with a key local to this Airship
                    // so attackers can't just spam a string constant to
                    // make the person decrypting these strings freak out
                    // and assume the password was compromised.
                    //
                    // False positives are bad. This gives the sysadmin a
                    // surefire way to reliably verify that a log entry is
                    // due to two-factor authentication failing.
                    $message = '**Note: The password was correct; ' .
                        ' invalid 2FA token was provided.** ' .
                        (new \DateTime('now'))->format(\AIRSHIP_DATE_FORMAT);
                    $signed = Base64UrlSafe::encode(
                        Asymmetric::sign(
                            $message,
                            $state->keyring['notary.online_signing_key'],
                            true
                        )
                    );
                    $airBrake->registerLoginFailure(
                        $post['username'],
                        $_SERVER['REMOTE_ADDR'],
                        $fails,
                        new HiddenString($signed . $message)
                    );
                    $this->lens(
                        'login',
                        [
                            'post_response' => [
                                'message' => \__('Incorrect username or passphrase. Please try again.'),
                                'status' => 'error'
                            ]
                        ]
                    );
                }
            }
            if ($user['session_canary']) {
                $_SESSION['session_canary'] = $user['session_canary'];
            } elseif ($this->config('password-reset.logout')) {
                $_SESSION['session_canary'] = $this->acct->createSessionCanary($userID);
            }

            $idx = $state->universal['session_index']['user_id'];

            // Regenerate session ID:
            \session_regenerate_id(true);

            $_SESSION[$idx] = (int) $userID;

            if (!empty($post['remember'])) {
                $autoPilot = Gears::getName('AutoPilot');
                if (IDE_HACKS) {
                    $autoPilot = new AutoPilot();
                }
                $httpsOnly = (bool) $autoPilot::isHTTPSConnection();
                
                $this->airship_cookie->store(
                    $state->universal['cookie_index']['auth_token'],
                    $this->airship_auth->createAuthToken($userID),
                    \time() + ($state->universal['long-term-auth-expire'] ?? self::DEFAULT_LONGTERMAUTH_EXPIRE),
                    '/',
                    $state->universal['session_config']['cookie_domain'] ?? '',
                    $httpsOnly ?? false,
                    true
                );
            }
            \Airship\redirect($this->airship_cabin_prefix);
        } else {
            $fails = $airBrake->getFailedLoginAttempts(
                $post['username'],
                $_SERVER['REMOTE_ADDR']
            ) + 1;

            // If the server is setup (with an EncryptionPublicKey) and the
            // number of failures is above the log threshold, this will
            // encrypt the password guess with the public key so that only
            // the person in possession of the secret key can decrypt it.
            $airBrake->registerLoginFailure(
                $post['username'],
                $_SERVER['REMOTE_ADDR'],
                $fails,
                new HiddenString($post['passphrase'])
            );
            $this->lens(
                'login',
                [
                    'post_response' => [
                        'message' => \__('Incorrect username or passphrase. Please try again.'),
                        'status' => 'error'
                    ]
                ]
            );
        }
    }

    /**
     * Process account recovery
     *
     * @param array $post
     * @return bool
     */
    protected function processRecoverAccount(array $post): bool
    {
        $airBrake = Gears::get('AirBrake');
        if (IDE_HACKS) {
            $airBrake = new AirBrake();
        }
        $failFast = $airBrake->failFast(
            $post['username'],
            $_SERVER['REMOTE_ADDR'],
            $airBrake::ACTION_RECOVER
        );
        if ($failFast) {
            $this->lens(
                'recover_account',
                [
                    'form_message' =>
                        \__('You are doing that too fast. Please wait a few seconds and try again.')
                ]
            );
        } elseif (!$airBrake->getFastExit()) {
            $delay = $airBrake->getDelay(
                $post['username'],
                $_SERVER['REMOTE_ADDR'],
                $airBrake::ACTION_RECOVER
            );
            if ($delay > 0) {
                \usleep($delay * 1000);
            }
        }

        try {
            $recoverInfo = $this->acct->getRecoveryInfo($post['forgot_passphrase_for']);
        } catch (UserNotFound $ex) {
            // Username not found. Is this a harvester?
            $airBrake->registerAccountRecoveryAttempt(
                $post['username'],
                $_SERVER['REMOTE_ADDR']
            );
            $this->log(
                'Password reset attempt for nonexistent user.',
                LogLevel::NOTICE,
                [
                    'username' => $post['forgot_passphrase_for']
                ]
            );
            return false;
        }
        if (!$recoverInfo['allow_reset'] || empty($recoverInfo['email'])) {
            // Opted out or no email address? Act like the user doesn't exist.
            $airBrake->registerAccountRecoveryAttempt(
                $post['username'],
                $_SERVER['REMOTE_ADDR']
            );
            return false;
        }

        $token = $this->acct->createRecoveryToken((int) $recoverInfo['userid']);
        if (empty($token)) {
            return false;
        }

        $state = State::instance();
        if (IDE_HACKS) {
            $state->mailer = new Sendmail();
            $state->gpgMailer = new GPGMailer($state->mailer);
        }

        $message = (new Message())
            ->addTo($recoverInfo['email'], $post['username'])
            ->setSubject('Password Reset')
            ->setFrom($state->universal['email']['from'] ?? 'no-reply@' . $_SERVER['HTTP_HOST'])
            ->setBody($this->recoveryMessage($token));

        try {
            if (!empty($recoverInfo['gpg_public_key'])) {
                // This will be encrypted with the user's public key:
                $state->gpgMailer->send($message, $recoverInfo['gpg_public_key']);
            } else {
                // This will be sent as-is:
                $state->mailer->send($message);
            }
        } catch (InvalidArgumentException $ex) {
            return false;
        }
        return true;
    }

    /**
     * If the token is valid, log in as the user.
     *
     * @param string $token
     */
    protected function processRecoveryToken(string $token)
    {
        if (Util::stringLength($token) < UserAccounts::RECOVERY_CHAR_LENGTH) {
            \Airship\redirect($this->airship_cabin_prefix . '/login');
        }
        $selector = Util::subString($token, 0, 32);
        $validator = Util::subString($token, 32);

        $ttl = (int) $this->config('password-reset.ttl');
        if (empty($ttl)) {
            \Airship\redirect($this->airship_cabin_prefix . '/login');
        }
        $recoveryInfo = $this->acct->getRecoveryData($selector, $ttl);
        if (empty($recoveryInfo)) {
            \Airship\redirect($this->airship_cabin_prefix . '/login');
        }
        $calc = CryptoUtil::keyed_hash(
            $validator,
            CryptoUtil::raw_hash('' . $recoveryInfo['userid'])
        );
        if (\hash_equals($recoveryInfo['hashedtoken'], $calc)) {
            $state = State::instance();
            $idx = $state->universal['session_index']['user_id'];
            $_SESSION[$idx] = (int) $recoveryInfo['userid'];
            \Airship\redirect($this->airship_cabin_prefix . '/my/account');
        }
        \Airship\redirect($this->airship_cabin_prefix . '/login');
    }

    /**
     * @param string $token
     * @return string
     */
    protected function recoveryMessage(string $token): string
    {
        return \__("To recover your account, visit the URL below.") . "\n\n" .
            \Airship\LensFunctions\cabin_url() . 'forgot-password/' . $token . "\n\n" .
            \__("This access token will expire in an hour.");
    }

    /**
     * Save a user's preferences
     *
     * @param array $prefs
     * @param array $cabins
     * @param array $motifs
     * @return bool
     */
    protected function savePreferences(
        array $prefs = [],
        array $cabins = [],
        array $motifs = []
    ): bool {
        // Validate the motifs
        foreach ($prefs['motif'] as $cabin => $selectedMotif) {
            if (!\in_array($cabin, $cabins)) {
                unset($prefs['motif'][$cabin]);
                continue;
            }
            if (empty($selectedMotif)) {
                $prefs['motif'][$cabin] = null;
                continue;
            }
            list ($supplier, $motifName) = \explode('/', $selectedMotif);
            if (!$this->findMotif($motifs[$cabin], $supplier, $motifName)) {
                $prefs['motif'][$cabin] = null;
                continue;
            }
        }

        if ($this->acct->updatePreferences($this->getActiveUserId(), $prefs)) {
            $this->storeLensVar('post_response', [
                'message' => \__('Preferences saved successfully.'),
                'status' => 'success'
            ]);
            return true;
        }
        return false;
    }

    /**
     * Make sure the secret exists, then get the GoogleAuth object
     *
     * @param int $userID
     * @return GoogleAuth
     * @throws \Airship\Alerts\Security\UserNotLoggedIn
     */
    protected function twoFactorPreamble(int $userID = 0): GoogleAuth
    {
        if (!$userID) {
            $userID = $this->getActiveUserId();
        }
        $secret = $this->acct->getTwoFactorSecret($userID);
        if (empty($secret)) {
            if (!$this->acct->resetTwoFactorSecret($userID)) {
                \Airship\json_response(['test2']);
                \Airship\redirect($this->airship_cabin_prefix);
            }
            $secret = $this->acct->getTwoFactorSecret($userID);
        }
        return new GoogleAuth(
            $secret,
            new TOTP(
                0,
                $this->config('two-factor.period') ?? 30,
                $this->config('two-factor.length') ?? 6
            )
        );
    }

    /**
     * Gets [offset, limit] based on configuration
     *
     * @param string $page
     * @param int $per_page
     * @return int[]
     */
    protected function getOffsetAndLimit($page = null, int $per_page = 0)
    {
        if ($per_page === 0) {
            $per_page = $this->config('user-directory.per-page') ?? 20;
        }
        $page = (int) (!empty($page) ? $page : ($_GET['page'] ?? 0));
        if ($page < 1) {
            $page = 1;
        }
        return [($page - 1) * $per_page, $per_page];
    }
}
