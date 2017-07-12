<?php
declare(strict_types=1);
namespace TYPO3\CMS\Backend\Controller\Page;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * "Sort sub pages" controller - reachable from context menu "more" on page records
 */
class SortSubPagesController
{
    /**
     * ModuleTemplate object
     *
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * Constructor Method
     *
     * @var $moduleTemplate ModuleTemplate
     */
    public function __construct(ModuleTemplate $moduleTemplate = null)
    {
        $this->moduleTemplate = $moduleTemplate ?? GeneralUtility::makeInstance(ModuleTemplate::class);
    }

    /**
     * Main function Handling input variables and rendering main view
     *
     * @param $request ServerRequestInterface
     * @param $response ResponseInterface
     * @return ResponseInterface Response
     */
    public function mainAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $parentPageUid = (int)$request->getQueryParams()['id'];

        // Show only if there is a valid page and if this page may be viewed by the user
        $pageInformation = BackendUtility::readPageAccess($parentPageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (!is_array($pageInformation)) {
            // User has no permission on parent page, should not happen, just render an empty page
            $this->moduleTemplate->setContent('');
            $response->getBody()->write($this->moduleTemplate->renderContent());
            return $response;
        }

        // Doc header handling
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->moduleTemplate->getDocHeaderComponent()->setMetaInformation($pageInformation);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $cshButton = $buttonBar->makeHelpButton()
            ->setModuleName('pages_sort')
            ->setFieldName('pages_sort');
        $viewButton = $buttonBar->makeLinkButton()
            ->setOnClick(BackendUtility::viewOnClick($parentPageUid, '', BackendUtility::BEgetRootLine($parentPageUid)))
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:labels.showPage'))
            ->setIcon($iconFactory->getIcon('actions-view-page', Icon::SIZE_SMALL))
            ->setHref('#');
        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setModuleName('pages_sort')
            ->setGetVariables(['id']);
        $buttonBar->addButton($cshButton)->addButton($viewButton)->addButton($shortcutButton);

        // Main view setup
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName(
            'EXT:backend/Resources/Private/Templates/Page/SortSubPages.html'
        ));

        $isInWorkspace = $backendUser->workspace !== 0;
        $view->assign('isInWorkspace', $isInWorkspace);
        $view->assign('maxTitleLength', $backendUser->uc['titleLen'] ?? 20);
        $view->assign('parentPageUid', $parentPageUid);
        $view->assign('dateFormat', $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy']);
        $view->assign('timeFormat', $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm']);

        if (!$isInWorkspace) {
            // Apply new sorting if given
            $newSortBy = $request->getQueryParams()['newSortBy'] ?? null;
            if ($newSortBy && in_array($newSortBy, ['title', 'subtitle', 'crdate', 'tstamp'], true)) {
                $this->sortSubPagesByField($parentPageUid, (string)$newSortBy);
            } elseif ($newSortBy && $newSortBy === 'reverseCurrentSorting') {
                $this->reverseSortingOfPages($parentPageUid);
            }

            // Get sub pages, loop through them and add page/user specific permission details
            $pageRecords = $this->getSubPagesOfPage($parentPageUid);
            $hasInvisiblePage = false;
            $subPages = [];
            foreach ($pageRecords as $page) {
                $pageWithPermissions = [];
                $pageWithPermissions['record'] = $page;
                $calculatedPermissions = $backendUser->calcPerms($page);
                $pageWithPermissions['canEdit'] = $backendUser->isAdmin() || $calculatedPermissions & Permission::PAGE_EDIT;
                $canSeePage = $backendUser->isAdmin() || $calculatedPermissions & Permission::PAGE_SHOW;
                if ($canSeePage) {
                    $subPages[] = $pageWithPermissions;
                } else {
                    $hasInvisiblePage = true;
                }
            }
            $view->assign('subPages', $subPages);
            $view->assign('hasInvisiblePage', $hasInvisiblePage);
        }

        $this->moduleTemplate->setContent($view->render());
        $response->getBody()->write($this->moduleTemplate->renderContent());
        return $response;
    }

    /**
     * Sort sub pages of given uid by field name alphabetically
     *
     * @param int $parentPageUid Parent page uid
     * @param string $newSortBy Field name to sort by
     * @throws \RuntimeException If $newSortBy does not validate
     */
    protected function sortSubPagesByField(int $parentPageUid, string $newSortBy)
    {
        if (!in_array($newSortBy, ['title', 'subtitle', 'crdate', 'tstamp'], true)) {
            throw new \RuntimeException(
                'New sort by must be one of "title", "subtitle", "crdate" or tstamp',
                1498924810
            );
        }
        $subPages = $this->getSubPagesOfPage($parentPageUid, $newSortBy);
        if (!empty($subPages)) {
            $subPages = array_reverse($subPages);
            $this->persistNewSubPageOrder($parentPageUid, $subPages);
        }
    }

    /**
     * Reverse current sorting of sub pages
     *
     * @param int $parentPageUid Parent page uid
     */
    protected function reverseSortingOfPages(int $parentPageUid)
    {
        $subPages = $this->getSubPagesOfPage($parentPageUid);
        if (!empty($subPages)) {
            $this->persistNewSubPageOrder($parentPageUid, $subPages);
        }
    }

    /**
     * Store new sub page order
     *
     * @param int $parentPageUid Parent page uid
     * @param array $subPages List of sub pages in new order
     */
    protected function persistNewSubPageOrder(int $parentPageUid, array $subPages)
    {
        $commandArray = [];
        foreach ($subPages as $subPage) {
            $commandArray['pages'][$subPage['uid']]['move'] = $parentPageUid;
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commandArray);
        $dataHandler->process_cmdmap();
        BackendUtility::setUpdateSignal('updatePageTree');
    }

    /**
     * Get a list of sub pages with some all fields from given page.
     * Fetch all data fields for full page icon display
     *
     * @param int $parentPageUid Get sub pages from this pages
     * @param string $orderBy Order pages by this field
     * @return array
     */
    protected function getSubPagesOfPage(int $parentPageUid, string $orderBy = 'sorting'): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($parentPageUid, \PDO::PARAM_INT)
                )
            )
            ->orderBy($orderBy)
            ->execute()
            ->fetchAll();
    }

    /**
     * Returns LanguageService
     *
     * @return LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Returns current BE user
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }
}