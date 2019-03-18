<?
/**
 * bitrix
 *
 * карта сатйта
 *
 * переиндексация карты сайта
 * создать sitemap_xx.xml
 * объединить все карты в один файл sitemap_xx.xml
 * функция для агента
 *
 */
if (!\Bitrix\Main\Loader::includeModule('iblock') || !\Bitrix\Main\Loader::includeModule('seo'))
{
    return false;
}
use \Bitrix\Main;
use \Bitrix\Main\IO;
use \Bitrix\Main\SiteTable;
use \Bitrix\Main\Application;
use \Bitrix\Main\Localization\Loc;
use \Bitrix\Seo\RobotsFile;
use \Bitrix\Seo\SitemapTable;
use \Bitrix\Seo\SitemapFile;
use \Bitrix\Seo\SitemapIndex;
use \Bitrix\Seo\SitemapRuntime;
use \Bitrix\Seo\SitemapRuntimeTable;
Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/seo/admin/seo_sitemap.php');
class SeoSiteMapCustom
{
    protected static $ID;
    protected static $arSitemap;
    protected static $arSitemapSettings;

    /**
     * @param int $ID - ид карты
     *
     * @return bool
     */
    public static function Run($ID = 1)
    {
        require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_admin_before.php");
        $dbSitemap = SitemapTable::getById($ID);
        $arSitemap = $dbSitemap->fetch();
        if (!$arSitemap)
        {
            return false;
        }
        $arSitemap['SITE'] = SiteTable::getByPrimary($arSitemap['SITE_ID'])->fetch();
        if (!is_array($arSitemap))
        {
            return false;
        }
        else
        {
            $arSitemap['SETTINGS'] = unserialize($arSitemap['SETTINGS']);
            $arSitemapSettings = array(
                'SITE_ID'  => $arSitemap['SITE_ID'],
                'PROTOCOL' => $arSitemap['SETTINGS']['PROTO'] == 1 ? 'https' : 'http',
                'DOMAIN'   => $arSitemap['SETTINGS']['DOMAIN'],
            );
            self::$ID                = $ID;
            self::$arSitemap         = $arSitemap;
            self::$arSitemapSettings = $arSitemapSettings;
            $GLOBALS['NS']           = array();
        }
        return true;
    }

    /**
     * @param int   $PID
     * @param array $arSitemap
     * @param array $arCurrentDir
     * @param int   $sitemapFile
     */
    public static function GetFilesData($PID, $arSitemap, $arCurrentDir, $sitemapFile)
    {
        $arDirList = array();
        if ($arCurrentDir['ACTIVE'] == SitemapRuntimeTable::ACTIVE)
        {
            $list = \CSeoUtils::getDirStructure(
                $arSitemap['SETTINGS']['logical'] == 'Y',
                $arSitemap['SITE_ID'],
                $arCurrentDir['ITEM_PATH']
            );
            foreach($list as $dir)
            {
                $dirKey = "/" . ltrim($dir['DATA']['ABS_PATH'], "/");
                if ($dir['TYPE'] == 'F')
                {
                    if (!isset($arSitemap['SETTINGS']['FILE'][ $dirKey ]) || $arSitemap['SETTINGS']['FILE'][ $dirKey ] == 'Y')
                    {
                        if (preg_match($arSitemap['SETTINGS']['FILE_MASK_REGEXP'], $dir['FILE']))
                        {
                            $f = new IO\File($dir['DATA']['PATH'], $arSitemap['SITE_ID']);
                            $sitemapFile->addFileEntry($f);
                            $GLOBALS['NS']['files_count']++;
                        }
                    }
                }
                else
                {
                    if (!isset($arSitemap['SETTINGS']['DIR'][ $dirKey ]) || $arSitemap['SETTINGS']['DIR'][ $dirKey ] == 'Y')
                    {
                        $arDirList[] = $dirKey;
                    }
                }
            }
        }
        else
        {
            $len = strlen($arCurrentDir['ITEM_PATH']);
            if (!empty($arSitemap['SETTINGS']['DIR']))
            {
                foreach($arSitemap['SETTINGS']['DIR'] as $dirKey => $checked)
                {
                    if ($checked == 'Y')
                    {
                        if (strncmp($arCurrentDir['ITEM_PATH'], $dirKey, $len) === 0)
                        {
                            $arDirList[] = $dirKey;
                        }
                    }
                }
            }
            if (!empty($arSitemap['SETTINGS']['FILE']))
            {
                foreach ($arSitemap['SETTINGS']['FILE'] as $dirKey => $checked)
                {
                    if ($checked == 'Y')
                    {
                        if (strncmp($arCurrentDir['ITEM_PATH'], $dirKey, $len) === 0)
                        {
                            $fileName = IO\Path::combine(
                                SiteTable::getDocumentRoot($arSitemap['SITE_ID']),
                                $dirKey
                            );
                            if (!is_dir($fileName))
                            {
                                $f = new IO\File($fileName, $arSitemap['SITE_ID']);
                                if ($f->isExists() && !$f->isSystem() && preg_match($arSitemap['SETTINGS']['FILE_MASK_REGEXP'], $f->getName()))
                                {
                                    $sitemapFile->addFileEntry($f);
                                    $GLOBALS['NS']['files_count']++;
                                }
                            }
                        }
                    }
                }
            }
        }
        if (count($arDirList) > 0)
        {
            foreach($arDirList as $dirKey)
            {
                $arRuntimeData = array(
                    'PID'       => $PID,
                    'ITEM_PATH' => $dirKey,
                    'PROCESSED' => SitemapRuntimeTable::UNPROCESSED,
                    'ACTIVE'    => SitemapRuntimeTable::ACTIVE,
                    'ITEM_TYPE' => SitemapRuntimeTable::ITEM_TYPE_DIR,
                );
                SitemapRuntimeTable::add($arRuntimeData);
            }
        }
        SitemapRuntimeTable::update(
            $arCurrentDir['ID'],
            array(
                'PROCESSED' => SitemapRuntimeTable::PROCESSED,
            )
        );
    }

    /**
     * @param int $ID - ид карты
     *
     * @return string
     */
    public static function SaveFileNirisLinksSmartFilter($ID = 1)
    {
        if (!isset(self::$ID))
        {
            self::Run($ID);
        }
        $links_dir = new IO\Directory(Application::getDocumentRoot() . '/include/niris:links.smart.filter/');
        if ($links_dir->isExists())
        {
            $files = $links_dir->getChildren();
            if (count($files))
            {
                $links       = array();
                $index_file  = Application::getDocumentRoot() . '/sitemap_smartfilter.xml';
                foreach ($files as $file)
                {
                    $lastmod   = $file->getModificationTime();
                    $lastmod   = date('c', $lastmod - \CTimeZone::getOffset());
                    $file_data = $file->getContents();
                    $file_data = explode("\n", $file_data);
                    $file_data = array_diff($file_data, array(''));
                    if (count($file_data) > 0)
                    {
                        foreach ($file_data as $k => $str)
                        {
                            $str     = explode('|', $str);
                            $str     = explode('/catalog', $str[1]);
                            $str     = '/catalog' . $str[1];
                            $links[] = self::$arSitemapSettings['PROTOCOL'] . '://' . \CBXPunycode::toASCII(self::$arSitemapSettings['DOMAIN'], $e = NULL) . $str;
                        }
                    }
                }
                $links = array_unique($links);
                if (count($links) > 0)
                {
                    $file_index  = SitemapFile::XML_HEADER;
                    $file_index .= SitemapFile::FILE_HEADER;
                    foreach ($links as $l)
                    {
                        $file_index .= sprintf(
                            SitemapFile::ENTRY_TPL,
                            $l,
                            $lastmod
                        );
                    }
                    $file_index .= SitemapFile::FILE_FOOTER;
                    if (\Bitrix\Main\IO\File::isFileExists($index_file))
                    {
                        \Bitrix\Main\IO\File::deleteFile($index_file);
                    }
                    \Bitrix\Main\IO\File::putFileContents(
                        $index_file,
                        $file_index,
                        FILE_USE_INCLUDE_PATH
                    );
                }
            }
        }
        return 'SeoSiteMapCustom::SaveFileNirisLinksSmartFilter('.$ID.');';
    }

    /**
     * @param int $ID - ид карты
     *
     * @return string
     */
    public static function FinalSiteMap($ID = 1)
    {
        self::Start($ID);
        self::SaveFileNirisLinksSmartFilter();
        self::SaveFiles($ID);
        return 'SeoSiteMapCustom::FinalSiteMap('.$ID.');';
    }

    /**
     * @param int $ID - ид карты
     *
     * @return string
     */
    public static function Start($ID = 1)
    {
        self::Run($ID);
        $arValueSteps = array(
            'init'         => 1,
            'files'        => 40,
            'iblock_index' => 50,
            'iblock'       => 60,
            'forum_index'  => 70,
            'forum'        => 80,
            'index'        => 100,
        );
        $PID               = self::$ID;
        $arSitemap         = self::$arSitemap;
        $arSitemapSettings = self::$arSitemapSettings;
        \Bitrix\Main\IO\File::deleteFile(Application::getDocumentRoot() . '/' . $arSitemap['SETTINGS']['FILENAME_FILES']);
        foreach($arValueSteps as $key => $v)
        {
            if ($v == $arValueSteps['init'])
            {
                SitemapRuntimeTable::clearByPid($PID);
                $GLOBALS['NS']['time_start']  = microtime(true);
                $GLOBALS['NS']['files_count'] = 0;
                $GLOBALS['NS']['steps_count'] = 0;
                $bRootChecked = isset($arSitemap['SETTINGS']['DIR']['/']) && $arSitemap['SETTINGS']['DIR']['/'] == 'Y';
                $arRuntimeData = array(
                    'PID'       => $PID,
                    'ITEM_TYPE' => SitemapRuntimeTable::ITEM_TYPE_DIR,
                    'ITEM_PATH' => '/',
                    'PROCESSED' => SitemapRuntimeTable::UNPROCESSED,
                    'ACTIVE'    => $bRootChecked ? SitemapRuntimeTable::ACTIVE : SitemapRuntimeTable::INACTIVE,
                );
                SitemapRuntimeTable::add($arRuntimeData);
                $msg = Loc::getMessage('SITEMAP_RUN_FILES', array('#PATH#' => '/'));
                $sitemapFile = new SitemapRuntime($PID, $arSitemap['SETTINGS']['FILENAME_FILES'], $arSitemapSettings);
                $v++;
            }
            elseif ($v < $arValueSteps['files'])
            {
                $GLOBALS['NS']['steps_count']++;
                $sitemapFile    = new SitemapRuntime($PID, $arSitemap['SETTINGS']['FILENAME_FILES'], $arSitemapSettings);
                $stepDuration   = 15;
                $ts_finish      = microtime(true) + $stepDuration * 0.95;
                $bFinished      = false;
                $bCheckFinished = false;
                $dbRes          = null;
                while(!$bFinished && microtime(true) <= $ts_finish)
                {
                    if (!$dbRes)
                    {
                        $dbRes = SitemapRuntimeTable::getList(
                            array(
                                'order'  => array(
                                    'ITEM_PATH' => 'ASC'
                                ),
                                'filter' => array(
                                    'PID'       => $PID,
                                    'ITEM_TYPE' => SitemapRuntimeTable::ITEM_TYPE_DIR,
                                    'PROCESSED' => SitemapRuntimeTable::UNPROCESSED,
                                ),
                                'limit'  => 1000,
                            )
                        );
                    }
                    if ($arRes = $dbRes->Fetch())
                    {
                        self::GetFilesData($PID, $arSitemap, $arRes, $sitemapFile);
                        $bCheckFinished = false;
                    }
                    elseif (!$bCheckFinished)
                    {
                        $dbRes          = null;
                        $bCheckFinished = true;
                    }
                    else
                    {
                        $bFinished = true;
                    }
                }
                if (!$bFinished)
                {
                    if ($v < $arValueSteps['files'] - 1)
                    {
                        $v++;
                    }
                    $msg = Loc::getMessage('SITEMAP_RUN_FILES', array('#PATH#' => $arRes['ITEM_PATH']));
                }
                else
                {
                    if (!is_array($GLOBALS['NS']['XML_FILES']))
                    {
                        $GLOBALS['NS']['XML_FILES'] = array();
                    }
                    if ($sitemapFile->isNotEmpty())
                    {
                        if ($sitemapFile->isCurrentPartNotEmpty())
                        {
                            $sitemapFile->finish();
                        }
                        else
                        {
                            $sitemapFile->delete();
                        }
                        $GLOBALS['NS']['XML_FILES'] = array_merge($GLOBALS['NS']['XML_FILES'], $sitemapFile->getNameList());
                    }
                    else
                    {
                        $sitemapFile->delete();
                    }
                    $v   = $arValueSteps['files'];
                    $msg = Loc::getMessage('SITEMAP_RUN_FILE_COMPLETE', array('#FILE#' => $arSitemap['SETTINGS']['FILENAME_FILES']));
                }
            }
            elseif ($v < $arValueSteps['iblock_index'])
            {
                $GLOBALS['NS']['time_start'] = microtime(true);
                $arIBlockList                = array();
                $arIBlockList = $arSitemap['SETTINGS']['IBLOCK_ACTIVE'];
                if (count($arIBlockList) > 0)
                {
                    $arIBlocks = array();
                    $dbIBlock  = CIBlock::GetList(array(), array('ID' => array_keys($arIBlockList)));
                    while($arIBlock = $dbIBlock->Fetch())
                    {
                        $arIBlocks[$arIBlock['ID']] = $arIBlock;
                    }
                    foreach($arIBlockList as $iblockId => $iblockActive)
                    {
                        if ($iblockActive !== 'Y' || !array_key_exists($iblockId, $arIBlocks))
                        {
                            unset($arIBlockList[$iblockId]);
                        }
                        else
                        {
                            SitemapRuntimeTable::add(
                                array(
                                    'PID'       => $PID,
                                    'PROCESSED' => SitemapRuntimeTable::UNPROCESSED,
                                    'ITEM_ID'   => $iblockId,
                                    'ITEM_TYPE' => SitemapRuntimeTable::ITEM_TYPE_IBLOCK,
                                )
                            );
                            $fileName = str_replace(
                                array(
                                    '#IBLOCK_ID#',
                                    '#IBLOCK_CODE#',
                                    '#IBLOCK_XML_ID#'
                                ),
                                array(
                                    $iblockId,
                                    $arIBlocks[$iblockId]['CODE'],
                                    $arIBlocks[$iblockId]['XML_ID']
                                ),
                                $arSitemap['SETTINGS']['FILENAME_IBLOCK']
                            );
                            $sitemapFile = new SitemapRuntime($PID, $fileName, $arSitemapSettings);
                            if ($sitemapFile->isExists())
                            {
                                $sitemapFile->delete();
                            }
                        }
                    }
                }
                $GLOBALS['NS']['LEFT_MARGIN']    = 0;
                $GLOBALS['NS']['IBLOCK_LASTMOD'] = 0;
                $GLOBALS['NS']['IBLOCK']         = array();
                if (count($arIBlockList) <= 0)
                {
                    $v   = $arValueSteps['iblock'];
                    $msg = Loc::getMessage('SITEMAP_RUN_IBLOCK_EMPTY');
                }
                else
                {
                    $v   = $arValueSteps['iblock_index'];
                    $msg = Loc::getMessage('SITEMAP_RUN_IBLOCK');
                }
            }
            elseif ($v < $arValueSteps['iblock'])
            {
                $stepDuration      = 300;
                $ts_finish         = microtime(true) + $stepDuration * 0.95;
                $bFinished         = false;
                $bCheckFinished    = false;
                $currentIblock     = false;
                $iblockId          = 0;
                $dbOldIblockResult = null;
                $dbIblockResult    = null;
                while (!$bFinished && microtime(true) <= $ts_finish)
                {
                    if (!$currentIblock)
                    {
                        $arCurrentIBlock = false;
                        $dbRes           = SitemapRuntimeTable::getList(
                            array(
                                'order' => array(
                                    'ID' => 'ASC'
                                ),
                                'filter' => array(
                                    'PID'       => $PID,
                                    'ITEM_TYPE' => SitemapRuntimeTable::ITEM_TYPE_IBLOCK,
                                    'PROCESSED' => SitemapRuntimeTable::UNPROCESSED,
                                ),
                                'limit'  => 1,
                            )
                        );
                        $currentIblock = $dbRes->fetch();
                        if ($currentIblock) {
                            $iblockId        = intval($currentIblock['ITEM_ID']);
                            $dbIBlock        = CIBlock::GetByID($iblockId);
                            $arCurrentIBlock = $dbIBlock->Fetch();
                            if (!$arCurrentIBlock)
                            {
                                SitemapRuntimeTable::update(
                                    $currentIblock['ID'],
                                    array(
                                        'PROCESSED' => SitemapRuntimeTable::PROCESSED,
                                    )
                                );
                                $GLOBALS['NS']['LEFT_MARGIN']     = 0;
                                $GLOBALS['NS']['IBLOCK_LASTMOD']  = 0;
                                $GLOBALS['NS']['LAST_ELEMENT_ID'] = 0;
                                unset($GLOBALS['NS']['CURRENT_SECTION']);
                            }
                            else
                            {
                                if (strlen($arCurrentIBlock['LIST_PAGE_URL']) <= 0)
                                {
                                    $arSitemap['SETTINGS']['IBLOCK_LIST'][$iblockId] = 'N';
                                }
                                if (strlen($arCurrentIBlock['SECTION_PAGE_URL']) <= 0)
                                {
                                    $arSitemap['SETTINGS']['IBLOCK_SECTION'][$iblockId] = 'N';
                                }
                                if (strlen($arCurrentIBlock['DETAIL_PAGE_URL']) <= 0)
                                {
                                    $arSitemap['SETTINGS']['IBLOCK_ELEMENT'][$iblockId] = 'N';
                                }
                                $GLOBALS['NS']['IBLOCK_LASTMOD'] = max($GLOBALS['NS']['IBLOCK_LASTMOD'], MakeTimeStamp($arCurrentIBlock['TIMESTAMP_X']));
                                if ($GLOBALS['NS']['LEFT_MARGIN'] <= 0 && $arSitemap['SETTINGS']['IBLOCK_ELEMENT'][$iblockId] != 'N')
                                {
                                    $GLOBALS['NS']['CURRENT_SECTION'] = 0;
                                }
                                $fileName = str_replace(
                                    array(
                                        '#IBLOCK_ID#',
                                        '#IBLOCK_CODE#',
                                        '#IBLOCK_XML_ID#'
                                    ),
                                    array(
                                        $iblockId,
                                        $arCurrentIBlock['CODE'],
                                        $arCurrentIBlock['XML_ID']
                                    ),
                                    $arSitemap['SETTINGS']['FILENAME_IBLOCK']
                                );
                                $sitemapFile = new SitemapRuntime($PID, $fileName, $arSitemapSettings);
                            }
                        }
                    }
                    if (!$currentIblock)
                    {
                        $bFinished = true;
                    }
                    elseif (is_array($arCurrentIBlock))
                    {
                        if ($dbIblockResult == null)
                        {
                            if (isset($GLOBALS['NS']['CURRENT_SECTION']))
                            {
                                $dbIblockResult = CIBlockElement::GetList(
                                    array(
                                        'ID' => 'ASC'
                                    ),
                                    array(
                                        'IBLOCK_ID'  => $iblockId,
                                        'ACTIVE'     => 'Y',
                                        'SECTION_ID' => intval($GLOBALS['NS']['CURRENT_SECTION']),
                                        '>ID'        => intval($GLOBALS['NS']['LAST_ELEMENT_ID']),
                                        'SITE_ID'    => $arSitemap['SITE_ID'],
                                    ),
                                    false,
                                    array(
                                        'nTopCount' => 1000
                                    ),
                                    array(
                                        'ID',
                                        'TIMESTAMP_X',
                                        'DETAIL_PAGE_URL'
                                    )
                                );
                            }
                            else
                            {
                                $GLOBALS['NS']['LAST_ELEMENT_ID'] = 0;
                                $dbIblockResult = CIBlockSection::GetList(
                                    array(
                                        'LEFT_MARGIN' => 'ASC'
                                    ),
                                    array(
                                        'IBLOCK_ID'     => $iblockId,
                                        'GLOBAL_ACTIVE' => 'Y',
                                        '>LEFT_BORDER'  => intval($GLOBALS['NS']['LEFT_MARGIN']),
                                    ),
                                    false,
                                    array(
                                        'ID',
                                        'TIMESTAMP_X',
                                        'SECTION_PAGE_URL',
                                        'LEFT_MARGIN',
                                        'IBLOCK_SECTION_ID',
                                    ),
                                    array(
                                        'nTopCount' => 100
                                    )
                                );
                            }
                        }
                        if (isset($GLOBALS['NS']['CURRENT_SECTION']))
                        {
                            $arElement = $dbIblockResult->fetch();
                            if ($arElement)
                            {
                                $arElement['LANG_DIR'] = $arSitemap['SITE']['DIR'];
                                $bCheckFinished        = false;
                                $elementLastmod        = MakeTimeStamp($arElement['TIMESTAMP_X']);
                                $GLOBALS['NS']['IBLOCK_LASTMOD']  = max($GLOBALS['NS']['IBLOCK_LASTMOD'], $elementLastmod);
                                $GLOBALS['NS']['LAST_ELEMENT_ID'] = $arElement['ID'];
                                $GLOBALS['NS']['IBLOCK'][$iblockId]['E']++;
                                $url = \CIBlock::ReplaceDetailUrl($arElement['DETAIL_PAGE_URL'], $arElement, false, "E");
                                $sitemapFile->addIBlockEntry($url, $elementLastmod);
                            }
                            elseif (!$bCheckFinished)
                            {
                                $bCheckFinished = true;
                                $dbIblockResult = null;
                            }
                            else
                            {
                                $bCheckFinished = false;
                                unset($GLOBALS['NS']['CURRENT_SECTION']);
                                $GLOBALS['NS']['LAST_ELEMENT_ID'] = 0;
                                $dbIblockResult = null;
                                if ($dbOldIblockResult)
                                {
                                    $dbIblockResult    = $dbOldIblockResult;
                                    $dbOldIblockResult = null;
                                }
                            }
                        }
                        else
                        {
                            $arSection = $dbIblockResult->fetch();
                            if ($arSection)
                            {
                                $bCheckFinished       = false;
                                $sectionLastmod       = MakeTimeStamp($arSection['TIMESTAMP_X']);
                                $GLOBALS['NS']['LEFT_MARGIN']    = $arSection['LEFT_MARGIN'];
                                $GLOBALS['NS']['IBLOCK_LASTMOD'] = max($GLOBALS['NS']['IBLOCK_LASTMOD'], $sectionLastmod);
                                $bActive              = false;
                                $bActiveElement       = false;
                                if (isset($arSitemap['SETTINGS']['IBLOCK_SECTION_SECTION'][$iblockId][$arSection['ID']]))
                                {
                                    $bActive        = $arSitemap['SETTINGS']['IBLOCK_SECTION_SECTION'][$iblockId][$arSection['ID']] == 'Y';
                                    $bActiveElement = $arSitemap['SETTINGS']['IBLOCK_SECTION_ELEMENT'][$iblockId][$arSection['ID']] == 'Y';
                                }
                                elseif ($arSection['IBLOCK_SECTION_ID'] > 0)
                                {
                                    $dbRes = SitemapRuntimeTable::getList(
                                        array(
                                            'filter' => array(
                                                'PID'       => $PID,
                                                'ITEM_TYPE' => SitemapRuntimeTable::ITEM_TYPE_SECTION,
                                                'ITEM_ID'   => $arSection['IBLOCK_SECTION_ID'],
                                                'PROCESSED' => SitemapRuntimeTable::PROCESSED,
                                            ),
                                            'select' => array(
                                                'ACTIVE',
                                                'ACTIVE_ELEMENT'
                                            ),
                                            'limit'  => 1,
                                        )
                                    );
                                    $parentSection = $dbRes->fetch();
                                    if ($parentSection)
                                    {
                                        $bActive        = $parentSection['ACTIVE'] == SitemapRuntimeTable::ACTIVE;
                                        $bActiveElement = $parentSection['ACTIVE_ELEMENT'] == SitemapRuntimeTable::ACTIVE;
                                    }
                                }
                                else
                                {
                                    $bActive        = $arSitemap['SETTINGS']['IBLOCK_SECTION'][$iblockId] == 'Y';
                                    $bActiveElement = $arSitemap['SETTINGS']['IBLOCK_ELEMENT'][$iblockId] == 'Y';
                                }
                                $arRuntimeData = array(
                                    'PID'            => $PID,
                                    'ITEM_ID'        => $arSection['ID'],
                                    'ITEM_TYPE'      => SitemapRuntimeTable::ITEM_TYPE_SECTION,
                                    'ACTIVE'         => $bActive ? SitemapRuntimeTable::ACTIVE : SitemapRuntimeTable::INACTIVE,
                                    'ACTIVE_ELEMENT' => $bActiveElement ? SitemapRuntimeTable::ACTIVE : SitemapRuntimeTable::INACTIVE,
                                    'PROCESSED'      => SitemapRuntimeTable::PROCESSED,
                                );
                                if ($bActive)
                                {
                                    $GLOBALS['NS']['IBLOCK'][$iblockId]['S']++;
                                    $arSection['LANG_DIR'] = $arSitemap['SITE']['DIR'];
                                    $url = \CIBlock::ReplaceDetailUrl($arSection['SECTION_PAGE_URL'], $arSection, false, "S");
                                    $sitemapFile->addIBlockEntry($url, $sectionLastmod);
                                }
                                SitemapRuntimeTable::add($arRuntimeData);
                                if ($bActiveElement)
                                {
                                    $GLOBALS['NS']['CURRENT_SECTION'] = $arSection['ID'];
                                    $GLOBALS['NS']['LAST_ELEMENT_ID'] = 0;
                                    $dbOldIblockResult     = $dbIblockResult;
                                    $dbIblockResult        = null;
                                }
                            }
                            elseif (!$bCheckFinished)
                            {
                                unset($GLOBALS['NS']['CURRENT_SECTION']);
                                $bCheckFinished = true;
                                $dbIblockResult = null;
                            }
                            else
                            {
                                $bCheckFinished = false;
                                SitemapRuntimeTable::update(
                                    $currentIblock['ID'],
                                    array(
                                        'PROCESSED' => SitemapRuntimeTable::PROCESSED,
                                    )
                                );
                                if ($arSitemap['SETTINGS']['IBLOCK_LIST'][$iblockId] == 'Y' && strlen($arCurrentIBlock['LIST_PAGE_URL']) > 0)
                                {
                                    $GLOBALS['NS']['IBLOCK'][$iblockId]['I']++;
                                    $arCurrentIBlock['IBLOCK_ID'] = $arCurrentIBlock['ID'];
                                    $arCurrentIBlock['LANG_DIR']  = $arSitemap['SITE']['DIR'];
                                    $url = \CIBlock::ReplaceDetailUrl($arCurrentIBlock['LIST_PAGE_URL'], $arCurrentIBlock, false, "");
                                    $sitemapFile->addIBlockEntry($url, $GLOBALS['NS']['IBLOCK_LASTMOD']);
                                }
                                if ($sitemapFile->isNotEmpty())
                                {
                                    if ($sitemapFile->isCurrentPartNotEmpty())
                                    {
                                        $sitemapFile->finish();
                                    }
                                    else
                                    {
                                        $sitemapFile->delete();
                                    }
                                    if (!is_array($GLOBALS['NS']['XML_FILES']))
                                    {
                                        $GLOBALS['NS']['XML_FILES'] = array();
                                    }
                                    $GLOBALS['NS']['XML_FILES'] = array_merge($GLOBALS['NS']['XML_FILES'], $sitemapFile->getNameList());
                                }
                                else
                                {
                                    $sitemapFile->delete();
                                }
                                $currentIblock         = false;
                                $GLOBALS['NS']['LEFT_MARGIN']     = 0;
                                $GLOBALS['NS']['IBLOCK_LASTMOD']  = 0;
                                unset($GLOBALS['NS']['CURRENT_SECTION']);
                                $GLOBALS['NS']['LAST_ELEMENT_ID'] = 0;
                            }
                        }
                    }
                }
                if ($v < $arValueSteps['iblock'] - 1)
                {
                    $msg = Loc::getMessage('SITEMAP_RUN_IBLOCK_NAME', array('#IBLOCK_NAME#' => $arCurrentIBlock['NAME']));
                    $v++;
                }
                if ($bFinished)
                {
                    $v   = $arValueSteps['iblock'];
                    $msg = Loc::getMessage('SITEMAP_RUN_FINALIZE');
                }
            }
            elseif ($v < $arValueSteps['forum_index'])
            {
                $GLOBALS['NS']['time_start'] = microtime(true);
                $arForumList      = array();
                if (!empty($arSitemap['SETTINGS']['FORUM_ACTIVE']))
                {
                    foreach($arSitemap['SETTINGS']['FORUM_ACTIVE'] as $forumId => $active)
                    {
                        if ($active == "Y")
                        {
                            $arForumList[$forumId] = "Y";
                        }
                    }
                }
                if (count($arForumList) > 0 && Main\Loader::includeModule('forum'))
                {
                    $arForums = array();
                    $db_res   = CForumNew::GetListEx(
                        array(),
                        array(
                            '@ID'     => array_keys($arForumList),
                            "ACTIVE"  => "Y",
                            "SITE_ID" => $arSitemap['SITE_ID'],
                            "!TOPICS" => 0,
                        )
                    );
                    while ($res = $db_res->Fetch())
                    {
                        $arForums[$res['ID']] = $res;
                    }
                    $arForumList = array_intersect_key($arForums, $arForumList);
                    foreach($arForumList as $id => $forum)
                    {
                        SitemapRuntimeTable::add(
                            array(
                                'PID'       => $PID,
                                'PROCESSED' => SitemapRuntimeTable::UNPROCESSED,
                                'ITEM_ID'   => $id,
                                'ITEM_TYPE' => SitemapRuntimeTable::ITEM_TYPE_FORUM
                            )
                        );
                        $fileName    = str_replace('#FORUM_ID#', $forumId, $arSitemap['SETTINGS']['FILENAME_FORUM']);
                        $sitemapFile = new SitemapRuntime($PID, $fileName, $arSitemapSettings);
                    }
                }
                $GLOBALS['NS']['FORUM_CURRENT_TOPIC'] = 0;
                if (count($arForumList) <= 0)
                {
                    $v   = $arValueSteps['forum'];
                    $msg = Loc::getMessage('SITEMAP_RUN_FORUM_EMPTY');
                }
                else
                {
                    $v   = $arValueSteps['forum_index'];
                    $msg = Loc::getMessage('SITEMAP_RUN_FORUM');
                }
            }
            elseif ($v < $arValueSteps['forum'])
            {
                $stepDuration   = 10;
                $ts_finish      = microtime(true) + $stepDuration * 0.95;
                $bFinished      = false;
                $bCheckFinished = false;
                $currentForum   = false;
                $forumId        = 0;
                $dbTopicResult  = null;
                $arTopic        = null;
                while (!$bFinished && microtime(true) <= $ts_finish && CModule::IncludeModule("forum"))
                {
                    if (!$currentForum)
                    {
                        $arCurrentForum = false;
                        $dbRes          = SitemapRuntimeTable::getList(
                            array(
                                'order'  => array(
                                    'ID' => 'ASC'
                                ),
                                'filter' => array(
                                    'PID'       => $PID,
                                    'ITEM_TYPE' => SitemapRuntimeTable::ITEM_TYPE_FORUM,
                                    'PROCESSED' => SitemapRuntimeTable::UNPROCESSED,
                                ),
                                'limit'  => 1,
                            )
                        );
                        $currentForum = $dbRes->fetch();
                        if ($currentForum)
                        {
                            $forumId = intval($currentForum['ITEM_ID']);
                            $db_res  = CForumNew::GetListEx(
                                array(),
                                array(
                                    'ID'      => $forumId,
                                    "ACTIVE"  => "Y",
                                    "SITE_ID" => $arSitemap['SITE_ID'],
                                    "!TOPICS" => 0,
                                )
                            );
                            $arCurrentForum = $db_res->Fetch();
                            if (!$arCurrentForum)
                            {
                                SitemapRuntimeTable::update(
                                    $currentForum['ID'],
                                    array(
                                        'PROCESSED' => SitemapRuntimeTable::PROCESSED,
                                    )
                                );
                            }
                            else
                            {
                                $fileName    = str_replace('#FORUM_ID#', $forumId, $arSitemap['SETTINGS']['FILENAME_FORUM']);
                                $sitemapFile = new SitemapRuntime($PID, $fileName, $arSitemapSettings);
                            }
                        }
                    }
                    if (!$currentForum)
                    {
                        $bFinished = true;
                    }
                    elseif (is_array($arCurrentForum))
                    {
                        $bActive = (array_key_exists($forumId, $arSitemap['SETTINGS']['FORUM_TOPIC']) && $arSitemap['SETTINGS']['FORUM_TOPIC'][$forumId] == "Y");
                        if ($bActive)
                        {
                            if ($dbTopicResult == null)
                            {
                                $dbTopicResult = CForumTopic::GetList(
                                    array(
                                        "LAST_POST_DATE" => "DESC"
                                    ),
                                    array_merge(
                                        array(
                                            "FORUM_ID" => $forumId,
                                            "APPROVED" => "Y"
                                        ),
                                        ($GLOBALS['NS']['FORUM_CURRENT_TOPIC'] > 0 ? array(">ID" => $GLOBALS['NS']["FORUM_CURRENT_TOPIC"]) : array())
                                    ),
                                    false,
                                    0,
                                    array(
                                        'nTopCount' => 100
                                    )
                                );
                            }
                            if (($arTopic = $dbTopicResult->fetch()) && $arTopic)
                            {
                                $GLOBALS['NS']["FORUM_CURRENT_TOPIC"] = $arTopic["ID"];
                                $url = CForumNew::PreparePath2Message(
                                    $arCurrentForum["PATH2FORUM_MESSAGE"],
                                    array(
                                        "FORUM_ID"        => $arCurrentForum["ID"],
                                        "TOPIC_ID"        => $arTopic["ID"],
                                        "TITLE_SEO"       => $arTopic["TITLE_SEO"],
                                        "MESSAGE_ID"      => "s",
                                        "SOCNET_GROUP_ID" => $arTopic["SOCNET_GROUP_ID"],
                                        "OWNER_ID"        => $arTopic["OWNER_ID"],
                                        "PARAM1"          => $arTopic["PARAM1"],
                                        "PARAM2"          => $arTopic["PARAM2"],
                                    )
                                );
                                $sitemapFile->addIBlockEntry($url, MakeTimeStamp($arTopic['LAST_POST_DATE']));
                            }
                        }
                        else
                        {
                            $url = CForumNew::PreparePath2Message(
                                $arCurrentForum["PATH2FORUM_MESSAGE"],
                                array(
                                    "FORUM_ID"        => $arCurrentForum["ID"],
                                    "TOPIC_ID"        => $arCurrentForum["TID"],
                                    "TITLE_SEO"       => $arCurrentForum["TITLE_SEO"],
                                    "MESSAGE_ID"      => "s",
                                    "SOCNET_GROUP_ID" => $arCurrentForum["SOCNET_GROUP_ID"],
                                    "OWNER_ID"        => $arCurrentForum["OWNER_ID"],
                                    "PARAM1"          => $arCurrentForum["PARAM1"],
                                    "PARAM2"          => $arCurrentForum["PARAM2"],
                                )
                            );
                            $sitemapFile->addIBlockEntry($url, MakeTimeStamp($arCurrentForum['LAST_POST_DATE']));
                        }
                        if (empty($arTopic))
                        {
                            $bCheckFinished = false;
                            SitemapRuntimeTable::update(
                                $currentForum['ID'],
                                array(
                                    'PROCESSED' => SitemapRuntimeTable::PROCESSED,
                                )
                            );
                            if ($sitemapFile->isNotEmpty())
                            {
                                if ($sitemapFile->isCurrentPartNotEmpty())
                                {
                                    $sitemapFile->finish();
                                }
                                else
                                {
                                    $sitemapFile->delete();
                                }
                                if (!is_array($GLOBALS['NS']['XML_FILES']))
                                {
                                    $GLOBALS['NS']['XML_FILES'] = array();
                                }
                                $GLOBALS['NS']['XML_FILES'] = array_merge($GLOBALS['NS']['XML_FILES'], $sitemapFile->getNameList());
                            }
                            else
                            {
                                $sitemapFile->delete();
                            }
                            $currentForum  = false;
                            $dbTopicResult = null;
                            $GLOBALS['NS']['FORUM_CURRENT_TOPIC'] = 0;
                        }
                    }
                }
                if ($v < $arValueSteps['forum'] - 1)
                {
                    $msg = Loc::getMessage('SITEMAP_RUN_FORUM_NAME', array('#FORUM_NAME#' => $arCurrentForum['NAME']));
                    $v++;
                }
                if ($bFinished)
                {
                    $v   = $arValueSteps['forum'];
                    $msg = Loc::getMessage('SITEMAP_RUN_FINALIZE');
                }
            }
            else
            {
                SitemapRuntimeTable::clearByPid($PID);
                $arFiles     = array();
                $sitemapFile = new SitemapIndex($arSitemap['SETTINGS']['FILENAME_INDEX'], $arSitemapSettings);
                if (count($GLOBALS['NS']['XML_FILES']) > 0)
                {
                    if (\Bitrix\Main\IO\File::isFileExists($sitemapFile->getPath()))
                    {
                        \Bitrix\Main\IO\File::deleteFile($sitemapFile->getPath());
                    }
                    $file_index  = SitemapFile::XML_HEADER;
                    $file_index .= SitemapFile::FILE_HEADER;
                    foreach($GLOBALS['NS']['XML_FILES'] as $key_file => $xmlFile)
                    {
                        $fileNameData = IO\Path::combine(
                            $sitemapFile->getSiteRoot(),
                            $xmlFile
                        );
                        $file = \Bitrix\Main\IO\File::getFileContents($fileNameData);
                        preg_match('~<url.*?>(.*)</url>~is', $file, $m);
                        if (isset($m['1']))
                        {
                            $file_index .= $m['1'] . '</url>';
                        }
                        \Bitrix\Main\IO\File::deleteFile($fileNameData);
                        unset($GLOBALS['NS']['XML_FILES'][$key_file]);
                    }
                    $file_index .= SitemapFile::FILE_FOOTER;
                    \Bitrix\Main\IO\File::putFileContents(
                        $sitemapFile->getPath(),
                        $file_index,
                        FILE_USE_INCLUDE_PATH
                    );
                }
                $arExistedSitemaps = array();
                if ($arSitemap['SETTINGS']['ROBOTS'] == 'Y')
                {
                    $sitemapUrl = $sitemapFile->getUrl();
                    $robotsFile = new RobotsFile($arSitemap['SITE_ID']);
                    $robotsFile->addRule(
                        array(
                            RobotsFile::SITEMAP_RULE,
                            $sitemapUrl
                        )
                    );
                }
                $v = $arValueSteps['index'];
            }
            if ($v == $arValueSteps['index'])
            {
                $msg = Loc::getMessage('SITEMAP_RUN_FINISH');
                SitemapTable::update($PID, array('DATE_RUN' => new Bitrix\Main\Type\DateTime()));
            }
            $GLOBALS['NS'] = isset($_REQUEST['NS']) && is_array($_REQUEST['NS']) ? $_REQUEST['NS'] : (array)$GLOBALS['NS'];
        }
        return 'SeoSiteMapCustom ' . $msg;
    }

    /**
     * @param int $ID - ид карты
     *
     * @return string
     */
    public static function InitSeoSitemap($ID = 1)
    {
        self::SaveFileNirisLinksSmartFilter($ID);
        self::SaveFiles($ID, true);
        return 'SeoSiteMapCustom::InitSeoSitemap('.$ID.');';
    }

    /**
     * @param int $ID - ид карты
     *
     * @return string
     */
    public static function SaveFiles($ID = 1, $no_index = false)
    {
        if (!isset(self::$ID))
        {
            self::Run($ID);
        }
        $docFiles   = array();
        $somePath   = $_SERVER['DOCUMENT_ROOT'] . self::$arSitemap['SITE']['DIR'];
        $index_file = self::$arSitemap['SETTINGS']['FILENAME_INDEX'];
        foreach (glob("$somePath/sitemap*.xml") as $docFile)
        {
            $docFile = str_replace('//', '/', $docFile);
            if (\Bitrix\Main\IO\File::isFileExists($docFile))
            {
                if ($no_index && stripos($docFile, $index_file) === false)
                {
                    $docFiles[] = $docFile;
                }
                elseif (!$no_index)
                {
                    $docFiles[] = $docFile;
                }
            }
        }
        $index_file = str_replace('//', '/', $_SERVER['DOCUMENT_ROOT'] . '/' . $index_file);
        if (count($docFiles) > 0)
        {
            $file_index  = SitemapFile::XML_HEADER;
            $file_index .= SitemapFile::FILE_HEADER;
            foreach($docFiles as $key_file => $xmlFile)
            {
                $file = \Bitrix\Main\IO\File::getFileContents($xmlFile);
                preg_match('~<url.*?>(.*)</url>~is', $file, $m);
                if (isset($m['1']))
                {
                    $file_index .= $m['1'] . '</url>';
                }
                \Bitrix\Main\IO\File::deleteFile($xmlFile);
            }
            $file_index .= SitemapFile::FILE_FOOTER;
            if (\Bitrix\Main\IO\File::isFileExists($index_file))
            {
                \Bitrix\Main\IO\File::deleteFile($index_file);
            }
            \Bitrix\Main\IO\File::putFileContents(
                $index_file,
                $file_index,
                FILE_USE_INCLUDE_PATH
            );
        }
        return 'SeoSiteMapCustom::SaveFiles('.$ID.', '.$no_index.');';
    }
}
?>