<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Landing;

use \Airship\Cabin\Bridge\Blueprint\CustomPages;
use \Airship\Cabin\Hull\Exceptions\CustomPageNotFoundException;
use \Airship\Engine\{
    AutoPilot,
    Bolt\Get,
    Gears,
    Security\Util,
    State
};
use \Psr\Log\LogLevel;

require_once __DIR__.'/init_gear.php';

/**
 * Class PageManager
 * @package Airship\Cabin\Bridge\Landing
 */
class PageManager extends LoggedInUsersOnly
{
    use Get;

    /**
     * @var CustomPages
     */
    protected $pg;

    /**
     * This function is called after the dependencies have been injected by
     * AutoPilot. Think of it as a user-land constructor.
     */
    public function airshipLand()
    {
        parent::airshipLand();
        $this->pg = $this->blueprint('CustomPages');
    }

    /**
     * Delete a directory in the custom page system
     *
     * @route pages/{string}/deleteDir
     * @param string $cabin
     */
    public function deleteDir(string $cabin = '')
    {
        $path = $this->determinePath($cabin);
        if (!\is1DArray($_GET)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->can('delete')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }

        // Split this up
        $pieces = \explode('/', $path);
        $dir = \array_shift($pieces);
        $path = \implode('/', $pieces);

        try {
            $dirInfo = $this->pg->getDirInfo($cabin, $path, $dir);
        } catch (CustomPageNotFoundException $ex) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
            return;
        }
        $secretKey = $this->config('recaptcha.secret-key');
        if (empty($secretKey)) {
            $this->lens('pages/bad_config');
        }

        $post = $this->post();
        if (!empty($post)) {
            if (isset($post['g-recaptcha-response'])) {
                $rc = \Airship\getReCaptcha(
                    $secretKey,
                    $this->config('recaptcha.curl-opts') ?? []
                );
                $resp = $rc->verify(
                    $post['g-recaptcha-response'],
                    $_SERVER['REMOTE_ADDR']
                );
                if ($resp->isSuccess()) {
                    // CAPTCHA verification and CSRF token both passed
                    if ($this->processDeleteDir(
                        (int) $dirInfo['directoryid'],
                        $post,
                        $cabin,
                        $cabins
                    )
                    ) {
                        // Return to the parent directory.
                        \Airship\redirect(
                            $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/'),
                            [
                                'dir' => $path
                            ]
                        );
                    }
                }
            }
        }

        $this->lens('pages/dir_delete', [
            'cabins' => $cabins,
            'custom_dir_tree' => $this->pg->getCustomDirTree(
                $cabins,
                0,
                (int) $dirInfo['directoryid']
            ),
            'dirinfo' => $dirInfo,
            'config' => $this->config(),
            // UNTRUSTED, PROVIDED BY THE USER:
            'parent' => $path,
            'dir' => $dir,
            'cabin' => $cabin,
            'pathinfo' => \Airship\chunk($path)
        ]);
    }

    /**
     * @route pages/{string}/deletePage
     * @param string $cabin
     */
    public function deletePage(string $cabin = '')
    {
        $page = [];
        $path = $this->determinePath($cabin);
        if (\is1DArray($_GET)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->can('delete')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }

        try {
            $page = $this->pg->getPageInfo($cabin, $path, $_GET['page']);
        } catch (CustomPageNotFoundException $ex) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }

        $secretKey = $this->config('recaptcha.secret-key');
        if (empty($secretKey)) {
            $this->lens('pages/bad_config');
        }
        $post = $this->post();
        if (!empty($post)) {
            if (isset($post['g-recaptcha-response'])) {
                $rc = \Airship\getReCaptcha(
                    $secretKey,
                    $this->config('recaptcha.curl-opts') ?? []
                );
                $resp = $rc->verify($post['g-recaptcha-response'], $_SERVER['REMOTE_ADDR']);
                if ($resp->isSuccess()) {
                    // CAPTCHA verification and CSRF token both passed
                    $this->processDeletePage(
                        (int) $page['pageid'],
                        $post,
                        $cabin,
                        $path
                    );
                }
            }
        }

        $this->lens('pages/page_delete', [
            'cabins' => $cabins,
            'pageinfo' => $page,
            'config' => $this->config(),
            // UNTRUSTED, PROVIDED BY THE USER:
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => \Airship\chunk($path)
        ]);
    }

    /**
     * We're going to edit a directory
     *
     * @route pages/{string}/edit
     * @param string $cabin
     */
    public function editPage(string $cabin = '')
    {
        $page = [];
        $path = $this->determinePath($cabin);
        if (!\is1DArray($_GET)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->can('update')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        try {
            $page = $this->pg->getPageInfo($cabin, $path, $_GET['page']);
        } catch (CustomPageNotFoundException $ex) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }
        $latest = $this->pg->getLatestDraft($page['pageid']);

        $post = $this->post();
        if (!empty($post)) {
            $this->processEditPage(
                (int) $page['pageid'],
                $post,
                $cabin,
                $path
            );
        }

        $this->lens('pages/page_edit', [
            'cabins' => $cabins,
            'pageinfo' => $page,
            'latest' => $latest,
            // UNTRUSTED, PROVIDED BY THE USER:
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => \Airship\chunk($path)
        ]);
    }

    /**
     * List all of the subdirectories and custom pages in a given directory
     *
     * @param pages /{string}
     * @param string $cabin
     */
    public function forCabin(string $cabin = '')
    {
        $path = $this->determinePath($cabin);
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $this->pg->setCabin($cabin);
        // Let's populate the subdirectories for the current directory
        try {
            $dirs = $this->pg->listSubDirectories($path, $cabin);
        } catch (CustomPageNotFoundException $ex) {
            if (!empty($path)) {
                \Airship\redirect(
                    $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
                );
            }
            $dirs = [];
        }
        // Let's populate the subdirectories for the current directory
        try {
            $pages = $this->pg->listCustomPages($path, $cabin);
        } catch (CustomPageNotFoundException $ex) {
            $pages = [];
        }
        $this->lens('pages_list', [
            'cabins' => $cabins,
            'dirs' => $dirs,
            'pages' => $pages,

            // UNTRUSTED, PROVIDED BY THE USER:
            'current' => $path,
            'cabin' => $cabin,
            'path' => \Airship\chunk($path)
        ]);
    }

    /**
     * Serve the index page
     * @route pages
     */
    public function index()
    {
        $this->lens('pages', [
            'cabins' => $this->getCabinNamespaces()
        ]);
    }

    /**
     * We're going to create a directory
     *
     * @route pages/{string}/newDir
     * @param string $cabin
     */
    public function newDir(string $cabin = '')
    {
        $path = $this->determinePath($cabin);
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->can('create')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $post = $this->post();
        if (!empty($post)) {
            $this->processNewDir(
                $cabin,
                $path,
                $post
            );
        }

        $this->lens('pages/dir_new', [
            'cabins' => $cabins,
            // UNTRUSTED, PROVIDED BY THE USER:
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => \Airship\chunk($path)
        ]);
    }

    /**
     * Create a new page
     *
     * @route pages/{string}/newPage
     * @param string $cabin
     */
    public function newPage(string $cabin = '')
    {
        $path = $this->determinePath($cabin);
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->can('create')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }

        $post = $this->post();
        if (!empty($post)) {
            $this->processNewPage(
                $cabin,
                $path,
                $post
            );
        }

        $this->lens('pages/page_new', [
            'cabins' => $cabins,
            // UNTRUSTED, PROVIDED BY THE USER:
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => \Airship\chunk($path)
        ]);
    }

    /**
     * We're going to move/rename a directory
     *
     * @param string $cabin
     * @route pages/{string}/renameDir
     */
    public function renameDir(string $cabin)
    {
        $path = $this->determinePath($cabin);
        if (!\is1DArray($_GET)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->can('delete')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }

        // Split this up
        $pieces = \explode('/', $path);
        $dir = \array_shift($pieces);
        $path = \implode('/', $pieces);

        try {
            $dirInfo = $this->pg->getDirInfo($cabin, $path, $dir);
        } catch (CustomPageNotFoundException $ex) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
            return;
        }

        $post = $this->post();
        if (!empty($post)) {
            // CAPTCHA verification and CSRF token both passed
            if ($this->processMoveDir(
                    $dirInfo,
                    $post,
                    $cabin,
                    $cabins
            )) {
                // Return to the parent directory.
                \Airship\redirect(
                    $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/'),
                    [
                        'dir' => $path
                    ]
                );
            }
        }
        $this->lens(
            'pages/dir_move',
            [
                'cabins' => $cabins,
                'custom_dir_tree' => $this->pg->getCustomDirTree(
                    $cabins,
                    $dirInfo['parent'] ?? 0,
                    (int) $dirInfo['directoryid']
                ),
                'dirinfo' => $dirInfo,
                'config' => $this->config(),
                    // UNTRUSTED, PROVIDED BY THE USER:
                'parent' => $path,
                'dir' => $dir,
                'cabin' => $cabin,
                'pathinfo' => \Airship\chunk($path)
            ]
        );
    }

    /**
     * We're going to create a directory
     *
     * @route pages/{string}/renamePage
     * @param string $cabin
     */
    public function renamePage(string $cabin)
    {
        $page = [];
        $path = $this->determinePath($cabin);
        if (\count($_GET) !== \count($_GET, \COUNT_RECURSIVE)) {
            \Airship\redirect($this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/'));
        }
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        // If you can't publish, you can't make a permanent change like this.
        if (!$this->can('publish')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        try {
            $page = $this->pg->getPageInfo(
                $cabin,
                $path,
                $_GET['page']
            );
        } catch (CustomPageNotFoundException $ex) {
            $this->log(
                'Page not found',
                LogLevel::NOTICE,
                [
                    'exception' => \Airship\throwableToArray($ex)
                ]
            );
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }

        $post = $this->post();
        if (!empty($post)) {
            $this->processMovePage(
                $page,
                $post,
                $cabin,
                $path
            );
        }

        $this->lens('pages/page_move', [
            'cabins' => $cabins,
            'pageinfo' => $page,
            // UNTRUSTED, PROVIDED BY THE USER:
            'all_dirs' => $this->pg->getCustomDirTree($cabins, 0),
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => \Airship\chunk($path)
        ]);
    }

    /**
     * We're going to view a page's history
     *
     * @route pages/{string}/history
     * @param string $cabin
     */
    public function pageHistory(string $cabin = '')
    {
        $page = [];
        $history = [];
        $path = $this->determinePath($cabin);
        if (!\is1DArray($_GET)) {
            \Airship\redirect($this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/'));
        }
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->can('read')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        try {
            $page = $this->pg->getPageInfo($cabin, $path, $_GET['page']);
            $history = $this->pg->getHistory((int) $page['pageid']);
        } catch (CustomPageNotFoundException $ex) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }

        $this->lens('pages/page_history', [
            'cabins' => $cabins,
            'pageinfo' => $page,
            'history' => $history,
            // UNTRUSTED, PROVIDED BY THE USER:
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => \Airship\chunk($path)
        ]);
    }

    /**
     * We're going to view a page's history
     *
     * @route pages/{string}/history/diff/{string}/{string}
     * @param string $cabin
     * @param string $leftUnique
     * @param string $rightUnique
     */
    public function pageHistoryDiff(string $cabin, string $leftUnique, string $rightUnique)
    {
        try {
            $left = $this->pg->getPageVersionByUniqueId($leftUnique);
            $right = $this->pg->getPageVersionByUniqueId($rightUnique);
        } catch (CustomPageNotFoundException $ex) {
            $this->log(
                'Page not found',
                LogLevel::NOTICE,
                [
                    'exception' => \Airship\throwableToArray($ex)
                ]
            );
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
            return;
        }
        $this->lens('pages/page_history_diff', [
            'left' => $left,
            'right' => $right
        ]);
    }

    /**
     * We're going to view a page's history
     *
     * @route pages/{string}/history/view/{string}
     * @param string $cabin
     * @param string $uniqueId
     */
    public function pageHistoryView(string $cabin, string $uniqueId)
    {
        $page = [];
        $version = [];
        $path = $this->determinePath($cabin);
        if (!\is1DArray($_GET)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        if (!$this->can('read')) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        try {
            $version = $this->pg->getPageVersionByUniqueId($uniqueId);
            if (!empty($version['metadata'])) {
                $version['metadata'] = \json_decode($version['metadata'], true);
            }
            $page = $this->pg->getPageById($version['page']);
        } catch (CustomPageNotFoundException $ex) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . \trim($cabin, '/')
            );
        }
        $prevUnique = $this->pg->getPrevVersionUniqueId(
            (int) $version['page'],
            $version['versionid']
        );
        $nextUnique = $this->pg->getNextVersionUniqueId(
            (int) $version['page'],
            $version['versionid']
        );
        $latestId = $this->pg->getLatestVersionId(
            (int) $version['page']
        );

        $this->lens('pages/page_history_view', [
            'cabins' => $cabins,
            'pageinfo' => $page,
            'version' => $version,
            'latestId' => $latestId,
            'prev_url' => $prevUnique,
            'next_url' => $nextUnique,
            // UNTRUSTED, PROVIDED BY THE USER:
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => \Airship\chunk($path)
        ]);
    }

    /**
     * Business logic.
     *
     * @param string $cabin
     * @return string
     */
    protected function determinePath(string &$cabin): string
    {
        $this->httpGetParams($cabin);
        if (!\array_key_exists('dir', $_GET)) {
            return '';
        }
        if (!\is_string($_GET['dir'])) {
            return '';
        }
        return $_GET['dir'];
    }

    /**
     * @param int $targetID
     * @param array $post
     * @param string $oldCabin
     * @param array $cabins
     * @return bool
     */
    protected function processDeleteDir(
        int $targetID,
        array $post = [],
        string $oldCabin = '',
        array $cabins = []
    ): bool {
        if (empty($post['move_contents'])) {
            // Delete everything
            return $this->pg->recursiveDelete($targetID);
        }
        // We're moving the contents
        if (\is_numeric($post['move_destination'])) {
            // To a different directory...
            $destination = (int) $post['move_destination'];
            $newCabin = $this->pg->getCabinForDirectory($destination);
            $this->pg->movePagesToDir(
                $targetID,
                $destination,
                !empty($post['create_redirect']),
                $oldCabin,
                $newCabin,
                $this->pg->getDirectoryPieces($destination)
            );
        } else {
            if (!\in_array($post['move_destination'], $cabins)) {
                // Cabin doesn't exist!
                return false;
            }
            // To the root directory of a different cabin...
            // To a different directory...
            $this->pg->movePagesToDir(
                $targetID,
                0,
                !empty($post['create_redirect']),
                $oldCabin,
                $post['move_destination']
            );
        }
        return $this->pg->deleteDir($targetID);
    }

    /**
     * Confirm deletion
     *
     * @param int $pageId
     * @param array $post
     * @param string $cabin
     * @param string $dir
     * @return mixed
     */
    protected function processDeletePage(
        int $pageId,
        array $post = [],
        string $cabin = '',
        string $dir = ''
    ): bool {
        $this->log(
            'Attempting to delete a page',
            LogLevel::ALERT,
            [
                'pageId' => $pageId,
                'cabin' => $cabin,
                'dir' => $dir
            ]
        );
        $oldURL = $this->pg->getPathByPageId((int) $pageId);
        if ($this->pg->deletePage($pageId)) {
            if (!empty($post['create_redirect']) && !empty($post['redirect_to'])) {
                $this->pg->createSameCabinRedirect(
                    $oldURL,
                    $post['redirect_to'],
                    $cabin
                );
            }
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/'.$cabin, [
                'dir' => $dir
            ]);
        }
    }

    /**
     * Create a new page in the current directory
     *
     * @param int $pageId
     * @param array $post
     * @param string $cabin
     * @param string $dir
     * @return mixed
     */
    protected function processEditPage(
        int $pageId,
        array $post = [],
        string $cabin = '',
        string $dir = ''
    ): bool {
        $required = [
            'format',
            'page_body',
            'save_btn',
            'metadata'
        ];
        if (!\Airship\all_keys_exist($required, $post)) {
            return false;
        }
        if ($this->isSuperUser()) {
            $raw = !empty($post['raw']);
        } else {
            $raw = null; // Don't set
        }
        $cache = !empty($post['cache']);
        if ($this->can('publish')) {
            $publish = $post['save_btn'] === 'publish';
        } elseif ($this->can('update')) {
            $publish = false;
        } else {
            $this->storeLensVar(
                'post_response',
                [
                    'message' => \__('You do not have permission to edit pages.'),
                    'status' => 'error'
                ]
            );
            return false;
        }
        if ($this->pg->updatePage($pageId, $post, $publish, $raw, $cache)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/'.$cabin, [
                'dir' => $dir
            ]);
        }
        return true;
    }

    /**
     * Move a page
     *
     * @param array $page
     * @param array $post
     * @param string $cabin
     * @param string $dir
     * @return bool
     */
    protected function processMovePage(
        array $page,
        array $post,
        string $cabin, string $dir
    ): bool {
        if (\is_numeric($post['directory'])) {
            $post['cabin'] = $this->pg->getCabinForDirectory($post['directory']);
        } else {
            // We're setting this to the root directory of a cabin
            $post['cabin'] = $post['directory'];
            $post['directory'] = 0;
        }
        // Actually process the new page:
        if (
            $page['directory'] !== $post['directory']
                ||
            $page['cabin']     !== $post['cabin']
                ||
            $page['url']       !== $post['url']
        ) {
            $this->pg->movePage(
                (int) $page['pageid'],
                $post['url'],
                (int) $post['directory']
            );
            if (!empty($post['create_redirect'])) {
                $this->pg->createPageRedirect(
                    \Airship\keySlice($page, ['cabin', 'directory', 'url']),
                    \Airship\keySlice($post, ['cabin', 'directory', 'url'])
                );
            }
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/'.$cabin, [
                'dir' => $dir
            ]);
        }
        return false;
    }

    /**
     * Move/rename a directory.
     *
     * @param array $dirInfo
     * @param array $post
     * @param string $oldCabin
     * @param array $cabins
     * @return bool
     */
    protected function processMoveDir(
        array $dirInfo,
        array $post = [],
        string $oldCabin = '',
        array $cabins = []
    ): bool {
        $targetID = (int) $dirInfo['directoryid'];

        if (\is_numeric($post['move_destination'])) {
            $destination = (int) $post['move_destination'];
            $newCabin = $this->pg->getCabinForDirectory($destination);
            $newPieces = $this->pg->getDirectoryPieces($destination);
            \array_pop($newPieces);
            $newPieces[] = Util::charWhitelist(
                $post['url'],
                Util::NON_DIRECTORY
            );
            $newPath = \implode('/', $newPieces);
        } elseif (!\in_array($post['move_destination'], $cabins)) {
            // Cabin doesn't exist!
            return false;
        } else {
            $newCabin = $post['move_destination'];
            $newPath = Util::charWhitelist(
                $post['url'],
                Util::NON_DIRECTORY
            );
        }
        if (!empty($post['create_redirect'])) {
            $old = [
                'cabin' => $oldCabin,
                'path' => \implode('/', $this->pg->getDirectoryPieces($targetID))
            ];
            $new = [
                'cabin' => $newCabin,
                'path' => $newPath
            ];
            $this->pg->createRedirectsForMove($old, $new);
        }
        return $this->pg->moveDir(
            $targetID,
            $post['url'],
            $destination ?? 0,
            $newCabin
        );
    }

    /**
     * @param string $cabin
     * @param string $parent
     * @param array $post
     * @return bool
     */
    protected function processNewDir(
        string $cabin,
        string $parent,
        array $post = []
    ): bool {
        if (!\Airship\all_keys_exist(['url', 'save_btn'], $post)) {
            return false;
        }
        if ($this->pg->createDir($cabin, $parent, $post)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/pages/' . $cabin,
                [
                    'dir' => $parent
                ]
            );
        }
        return true;
    }

    /**
     * Create a new page in the current directory
     *
     * @param string $cabin
     * @param string $path
     * @param array $post
     * @return mixed
     */
    protected function processNewPage(
        string $cabin,
        string $path,
        array $post = []
    ): bool {
        $expected = [
            'url',
            'format',
            'page_body',
            'save_btn',
            'metadata'
        ];
        if (!\Airship\all_keys_exist($expected, $post)) {
            return false;
        }

        $url = $path . '/' . \str_replace('/', '_', $post['url']);
        if (!empty($post['ignore_collisions']) && $this->detectCollisions($url, $cabin)) {
            $this->storeLensVar(
                'post_response',
                [
                    'message' => \__('The given filename might conflict with another route in this Airship.'),
                    'status' => 'error'
                ]
            );
            return false;
        }
        $raw = $this->isSuperUser()
            ? !empty($post['raw'])
            : false;
        if ($this->can('publish')) {
            $publish = $post['save_btn'] === 'publish';
        } elseif ($this->can('create')) {
            $publish = false;
        } else {
            $this->storeLensVar(
                'post_response',
                [
                    'message' => \__('You do not have permission to create new pages.'),
                    'status' => 'error'
                ]
            );
            return false;
        }
        if ($this->pg->createPage($cabin, $path, $post, $publish, $raw)) {
            \Airship\redirect($this->airship_cabin_prefix . '/pages/'.$cabin, [
                'dir' => $path
            ]);
        }
        return true;
    }

    /**
     * Find probable collisions between patterns and cabin names, as well as hard-coded paths
     * in the current cabin. It does NOT look for collisions in custom pages, nor in page collisions
     * in foreign Cabins (outside of the Cabin itself).
     *
     * @param string $uri
     * @param string $cabin
     * @return bool
     * @throws \Airship\Alerts\GearNotFound
     * @throws \TypeError
     */
    protected function detectCollisions(string $uri, string $cabin): bool
    {
        $state = State::instance();
        $ap = Gears::getName('AutoPilot');
        if (!($ap instanceof AutoPilot)) {
            throw new \TypeError('AutoPilot Blueprint');
        }
        $nop = [];
        foreach ($state->cabins as $pattern => $cab) {
            if ($cab === $cabin) {
                // Let's check each existing route in the current cabin for a collision
                foreach ($cab['data']['routes'] as $route => $landing) {
                    $test = $ap::testLanding(
                        $ap::$patternPrefix . $route . '$',
                        $uri,
                        $nop,
                        true
                    );
                    if ($test) {
                        return true;
                    }
                }
            } else {
                // Let's check each cabin route for a pattern
                $test = $ap::testLanding(
                    $ap::$patternPrefix . $pattern,
                    $uri,
                    $nop,
                    true
                );
                if ($test) {
                    return true;
                }
            }
        }
        return \preg_match('#^(static|js|img|fonts|css)/#', $uri) === 0;
    }
}
