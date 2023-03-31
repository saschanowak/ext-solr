<?php

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

namespace ApacheSolrForTypo3\Solr\Tests\Integration\IndexQueue;

use ApacheSolrForTypo3\Solr\FrontendEnvironment\Exception\Exception as FrontendEnvironmentException;
use ApacheSolrForTypo3\Solr\IndexQueue\AdditionalIndexQueueItemIndexer;
use ApacheSolrForTypo3\Solr\IndexQueue\Indexer;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use ApacheSolrForTypo3\Solr\NoSolrConnectionFoundException;
use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use ApacheSolrForTypo3\Solr\Tests\Integration\IndexQueue\Helpers\DummyAdditionalIndexQueueItemIndexer;
use ApacheSolrForTypo3\Solr\Tests\Integration\IndexQueue\Helpers\DummyIndexer;
use ApacheSolrForTypo3\Solr\Tests\Integration\IntegrationTest;
use Doctrine\DBAL\ConnectionException as DBALConnectionException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Schema\SchemaException;
use InvalidArgumentException;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionException;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Database\Schema\Exception\StatementException;
use TYPO3\CMS\Core\Database\Schema\Exception\UnexpectedSignalReturnValueTypeException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Middleware\NormalizedParamsAttribute;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Exception as TestingFrameworkCoreException;
use UnexpectedValueException;

/**
 * Testcase for the record indexer
 *
 * @author Timo Schmidt
 */
class IndexerTest extends IntegrationTest
{
    protected bool $skipImportRootPagesAndTemplatesForConfiguredSites = true;

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/solr',
        '../vendor/apache-solr-for-typo3/solr/Tests/Integration/Fixtures/Extensions/fake_extension2',
    ];

    /**
     * @var Queue|null
     */
    protected ?Queue $indexQueue = null;

    /**
     * @var Indexer|null
     */
    protected ?Indexer $indexer = null;

    /**
     * @throws TestingFrameworkCoreException
     * @throws NoSuchCacheException
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeDefaultSolrTestSiteConfiguration();
        $this->indexQueue = GeneralUtility::makeInstance(Queue::class);
        $this->indexer = GeneralUtility::makeInstance(Indexer::class);

        /* @var BackendUserAuthentication $beUser */
        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $beUser;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $_SERVER['HTTP_HOST'] = 'test.local.typo3.org';
        $request = ServerRequestFactory::fromGlobals();
        $handlerMock = $this->createMock(RequestHandlerInterface::class);
        $normalizer = new NormalizedParamsAttribute();
        $normalizer->process($request, $handlerMock);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanUpSolrServerAndAssertEmpty();
        unset(
            $this->indexQueue,
            $this->indexer,
        );
    }

    /**
     * This testcase should check if we can queue a custom record with MM relations.
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexItemWithMMRelation(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();

        $this->importDataSetFromFixture('can_index_custom_record_with_mm_relation.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 88);

        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');

        self::assertStringContainsString('"category_stringM":["the tag"]', $solrContent, 'Did not find MM related tag');
        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"testnews"', $solrContent, 'Could not index document into solr');
        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * @return array
     */
    public function getTranslatedRecordDataProvider(): array
    {
        return [
            'with_l_parameter' => ['can_index_custom_translated_record_with_l_param.xml'],
            'without_l_parameter' => ['can_index_custom_translated_record_without_l_param.xml'],
            'without_l_parameter_and_content_fallback' => ['can_index_custom_translated_record_without_l_param_and_content_fallback.xml'],
        ];
    }

    /**
     * @dataProvider getTranslatedRecordDataProvider
     * @test
     *
     * @param $fixture
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function testCanIndexTranslatedCustomRecord($fixture): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->cleanUpSolrServerAndAssertEmpty('core_de');

        $this->importDataSetFromFixture($fixture);

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 88);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 777);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        self::assertStringContainsString('"numFound":2', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"original"', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"original2"', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"url":"http://testone.site/en/?tx_foo%5Buid%5D=88', $solrContent, 'Can not build typolink as expected');
        self::assertStringContainsString('"url":"http://testone.site/en/?tx_foo%5Buid%5D=777', $solrContent, 'Can not build typolink as expected');

        $this->waitToBeVisibleInSolr('core_de');
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_de/select?q=*:*');
        self::assertStringContainsString('"numFound":2', $solrContent, 'Could not find translated record in solr document into solr');
        if ($fixture === 'can_index_custom_translated_record_without_l_param_and_content_fallback.xml') {
            self::assertStringContainsString('"title":"original"', $solrContent, 'Could not index  translated document into solr');
            self::assertStringContainsString('"title":"original2"', $solrContent, 'Could not index  translated document into solr');
        } else {
            self::assertStringContainsString('"title":"translation"', $solrContent, 'Could not index  translated document into solr');
            self::assertStringContainsString('"title":"translation2"', $solrContent, 'Could not index  translated document into solr');
        }
        self::assertStringContainsString('"url":"http://testone.site/de/?tx_foo%5Buid%5D=88', $solrContent, 'Can not build typolink as expected');
        self::assertStringContainsString('"url":"http://testone.site/de/?tx_foo%5Buid%5D=777', $solrContent, 'Can not build typolink as expected');

        $this->cleanUpSolrServerAndAssertEmpty();
        $this->cleanUpSolrServerAndAssertEmpty('core_de');
    }

    /**
     * This testcase should check if we can queue a custom record with ordered MM relations.
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexItemWithMMRelationsInTheExpectedOrder(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_index_custom_record_with_multiple_mm_relations.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 88);

        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the values from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContentJson = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $solrContent = json_decode($solrContentJson, true);
        $solrContentResponse = $solrContent['response'];

        self::assertArrayHasKey('docs', $solrContentResponse, 'Did not find docs in solr response');

        $solrDocs = $solrContentResponse['docs'];

        self::assertCount(1, $solrDocs, 'Could not found index document into solr');
        self::assertIsArray($solrDocs[0]);
        self::assertEquals('testnews', (string)$solrDocs[0]['title'], 'Title of Solr document is not as expected.');
        self::assertArrayHasKey('category_stringM', $solrDocs[0], 'Did not find MM related tags.');
        self::assertCount(2, $solrDocs[0]['category_stringM'], 'Did not find all MM related tags.');
        self::assertSame(['the tag', 'another tag'], $solrDocs[0]['category_stringM']);

        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * This testcase should check if we can queue a custom record with MM relations.
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexTranslatedItemWithMMRelation(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->cleanUpSolrServerAndAssertEmpty('core_de');
        $this->importDataSetFromFixture('can_index_custom_translated_record_with_mm_relation.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 88);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr('core_de');
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_de/select?q=*:*');

        self::assertStringContainsString('"category_stringM":["translated tag"]', $solrContent, 'Did not find MM related tag');
        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"translation"', $solrContent, 'Could not index document into solr');

        $this->cleanUpSolrServerAndAssertEmpty();
        $this->cleanUpSolrServerAndAssertEmpty('core_de');
    }

    /**
     * This testcase should check if we can queue a custom record with multiple MM relations.
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexMultipleMMRelatedItems(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_index_custom_record_with_multiple_mm_relations.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 88);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');

        $decodedSolrContent = json_decode($solrContent);
        $tags = $decodedSolrContent->response->docs[0]->tags_stringM;

        self::assertSame(['the tag', 'another tag'], $tags, 'Did not find MM related tags');
        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"testnews"', $solrContent, 'Could not index document into solr');

        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * This testcase should check if we can queue a custom record with MM relations and respect the additionalWhere clause.
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexItemWithMMRelationAndAdditionalWhere(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_index_custom_record_with_mm_relationAndAdditionalWhere.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 88);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');

        self::assertStringContainsString('"category_stringM":["another tag"]', $solrContent, 'Did not find MM related tag');
        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"testnews"', $solrContent, 'Could not index document into solr');
        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * This testcase should check if we can queue a custom record with MM relations and respect the additionalWhere clause.
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexItemWithMMRelationToATranslatedPage(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->cleanUpSolrServerAndAssertEmpty('core_de');
        $this->importDataSetFromFixture('can_index_custom_translated_record_with_mm_relation_to_a_page.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 88);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $this->waitToBeVisibleInSolr('core_de');

        $solrContentEn = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $solrContentDe = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_de/select?q=*:*');

        self::assertStringContainsString('"relatedPageTitles_stringM":["Related page"]', $solrContentEn, 'Can not find related page title');
        self::assertStringContainsString('"relatedPageTitles_stringM":["Translated related page"]', $solrContentDe, 'Can not find translated related page title');

        $this->cleanUpSolrServerAndAssertEmpty();
        $this->cleanUpSolrServerAndAssertEmpty('core_de');
    }

    /**
     * This testcase is used to check if direct relations can be resolved with the RELATION configuration
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexItemWithDirectRelation(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_index_custom_record_with_direct_relation.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 111);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');

        self::assertStringContainsString('"category_stringM":["the category"]', $solrContent, 'Did not find direct related category');
        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"testnews"', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"sysCategoryId_stringM":["1"]', $solrContent, 'Uid of related sys_category couldn\'t be resolved by using "foreignLabelField"');
        self::assertStringContainsString('"sysCategory_stringM":["sys_category"]', $solrContent, 'Label of related sys_category couldn\'t be resolved by using "foreignLabelField" and "enableRecursiveValueResolution"');
        self::assertStringContainsString('"sysCategoryDescription_stringM":["sys_category description"]', $solrContent, 'Description of related sys_category couldn\'t be resolved by using "foreignLabelField" and "enableRecursiveValueResolution"');
        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * This testcase is used to check if multiple direct relations can be resolved with the RELATION configuration
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexItemWithMultipleDirectRelation(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_index_custom_record_with_multiple_direct_relations.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 111);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $decodedSolrContent = json_decode($solrContent);

        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"testnews"', $solrContent, 'Could not index document into solr');

        // @extensionScannerIgnoreLine
        $category_stringM = $decodedSolrContent->response->docs[0]->category_stringM;
        self::assertSame(['the category', 'the second category'], $category_stringM, 'Unexpected category_stringM value');
        // @extensionScannerIgnoreLine
        $sysCategoryId_stringM = $decodedSolrContent->response->docs[0]->sysCategoryId_stringM;
        self::assertSame(['1', '2'], $sysCategoryId_stringM, 'Unexpected sysCategoryId_stringM value');
        // @extensionScannerIgnoreLine
        $sysCategory_stringM = $decodedSolrContent->response->docs[0]->sysCategory_stringM;
        self::assertSame(['sys_category', 'sys_category 2'], $sysCategory_stringM, 'Unexpected sysCategory_stringM value');
        // @extensionScannerIgnoreLine
        $sysCategoryDescription_stringM = $decodedSolrContent->response->docs[0]->sysCategoryDescription_stringM;
        self::assertSame(['sys_category description', 'second sys_category description'], $sysCategoryDescription_stringM, 'Unexpected sysCategoryDescription_stringM value');

        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * This testcase is used to check if direct relations can be resolved with the RELATION configuration
     * and could be limited with an additionalWhere clause at the same time
     *
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canIndexItemWithDirectRelationAndAdditionalWhere(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();

        $this->importDataSetFromFixture('can_index_custom_record_with_direct_relationAndAdditionalWhere.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 111);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');

        self::assertStringContainsString('"category_stringM":["another category"]', $solrContent, 'Did not find direct related category');
        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"testnews"', $solrContent, 'Could not index document into solr');
        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function canUseConfigurationFromTemplateInRootLine(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_index_custom_record_with_configuration_in_rootline.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 111);
        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');

        self::assertStringContainsString('"fieldFromRootLine_stringS":"TESTNEWS"', $solrContent, 'Did not find field configured in rootline');
        self::assertStringContainsString('"title":"testnews"', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * @test
     *
     * @throws ReflectionException
     */
    public function canGetAdditionalDocumentsInterfaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueueIndexer']['indexItemAddDocuments'][] = AdditionalIndexQueueItemIndexer::class;
        $document = new Document();
        $metaData = ['item_type' => 'pages'];
        $record = [];
        $item = GeneralUtility::makeInstance(Item::class, $metaData, $record);
        $this->callInaccessibleMethod($this->indexer, 'getAdditionalDocuments', $item, 0, $document);
    }

    /**
     * @test
     * @throws ReflectionException
     */
    public function canGetAdditionalDocumentsNotImplementingInterface(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueueIndexer']['indexItemAddDocuments'][] = DummyIndexer::class;
        $document = new Document();
        $metaData = ['item_type' => 'pages'];
        $record = [];
        $item = GeneralUtility::makeInstance(Item::class, $metaData, $record);
        $this->callInaccessibleMethod($this->indexer, 'getAdditionalDocuments', $item, 0, $document);
    }

    /**
     * @test
     *
     * @throws ReflectionException
     */
    public function canGetAdditionalDocumentsNonExistingClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueueIndexer']['indexItemAddDocuments'][] = 'NonExistingClass';
        $document = new Document();
        $metaData = ['item_type' => 'pages'];
        $record = [];
        $item = GeneralUtility::makeInstance(Item::class, $metaData, $record);

        $this->callInaccessibleMethod($this->indexer, 'getAdditionalDocuments', $item, 0, $document);
    }

    /**
     * @test
     *
     * @throws ReflectionException
     */
    public function canGetAdditionalDocuments(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueueIndexer']['indexItemAddDocuments'][] = DummyAdditionalIndexQueueItemIndexer::class;
        $document = new Document();
        $metaData = ['item_type' => 'pages'];
        $record = [];
        $item = GeneralUtility::makeInstance(Item::class, $metaData, $record);

        $result = $this->callInaccessibleMethod($this->indexer, 'getAdditionalDocuments', $item, 0, $document);
        self::assertSame([], $result);
    }

    /**
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function testCanIndexCustomRecordOutsideOfSiteRoot(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_index_custom_record_outside_site_root.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 111);

        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');

        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"title":"external testnews"', $solrContent, 'Could not index document into solr');
        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SchemaException
     * @throws SiteNotFoundException
     * @throws StatementException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     * @throws UnexpectedSignalReturnValueTypeException
     */
    public function testCanIndexCustomRecordOutsideOfSiteRootWithTemplate(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_index_custom_record_outside_site_root_with_template.xml');

        $result = $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_bar', 1);

        self::assertTrue($result, 'Indexing was not indicated to be successful');

        // do we have the record in the index with the value from the mm relation?
        $this->waitToBeVisibleInSolr();
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        self::assertStringContainsString('"numFound":2', $solrContent, 'Could not index document into solr');

        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*&fq=site:testone.site');
        self::assertStringContainsString('"numFound":1', $solrContent, 'Could not index document into solr');
        self::assertStringContainsString('"url":"http://testone.site/en/"', $solrContent, 'Item was indexed with false site UID');
        $this->cleanUpSolrServerAndAssertEmpty();
    }

    /**
     * @param string $table
     * @param int $uid
     *
     * @return bool
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SiteNotFoundException
     * @throws Throwable
     */
    protected function addToQueueAndIndexRecord(string $table, int $uid): bool
    {
        $result = false;
        // write an index queue item
        $this->indexQueue->updateItem($table, $uid);

        // run the indexer
        $items = $this->indexQueue->getItems($table, $uid);
        foreach ($items as $item) {
            $result = $this->indexer->index($item);
        }

        return $result;
    }

    /**
     * @test
     *
     * @throws ReflectionException
     * @throws TestingFrameworkCoreException
     */
    public function getSolrConnectionsByItemReturnsNoDefaultConnectionIfRootPageIsHideDefaultLanguage(): void
    {
        $this->importDataSetFromFixture('can_index_with_rootPage_set_to_hide_default_language.xml');
        $itemMetaData = [
            'uid' => 1,
            'root' => 1,
            'item_type' => 'pages',
            'item_uid' => 1,
            'indexing_configuration' => '',
            'has_indexing_properties' => false,
        ];
        $item = new Item($itemMetaData);

        $result = $this->callInaccessibleMethod($this->indexer, 'getSolrConnectionsByItem', $item);

        self::assertInstanceOf(SolrConnection::class, $result[1], 'Expect SolrConnection object in connection array item with key 1.');
        self::assertCount(1, $result, 'Expect only one SOLR connection.');
        self::assertArrayNotHasKey(0, $result, 'Expect, that there is no solr connection returned for default language,');
    }

    /**
     * @test
     *
     * @throws ReflectionException
     * @throws TestingFrameworkCoreException
     */
    public function getSolrConnectionsByItemReturnsNoDefaultConnectionDefaultLanguageIsHiddenInSiteConfig(): void
    {
        $this->writeDefaultSolrTestSiteConfigurationForHostAndPort('http', 'localhost', 8999, true);
        $this->importDataSetFromFixture('can_index_with_rootPage_set_to_hide_default_language.xml');
        $itemMetaData = [
            'uid' => 1,
            'root' => 1,
            'item_type' => 'pages',
            'item_uid' => 1,
            'indexing_configuration' => '',
            'has_indexing_properties' => false,
        ];
        $item = new Item($itemMetaData);

        $result = $this->callInaccessibleMethod($this->indexer, 'getSolrConnectionsByItem', $item);

        self::assertEmpty($result[0], 'Connection for default language was expected to be empty');
        self::assertInstanceOf(SolrConnection::class, $result[1], 'Expect SolrConnection object in connection array item with key 1.');
        self::assertCount(1, $result, 'Expect only one SOLR connection.');
        self::assertArrayNotHasKey(0, $result, 'Expect, that there is no solr connection returned for default language,');
    }

    /**
     * @test
     *
     * @throws DBALConnectionException
     * @throws DBALDriverException
     * @throws DBALException
     * @throws FrontendEnvironmentException
     * @throws NoSolrConnectionFoundException
     * @throws SiteNotFoundException
     * @throws TestingFrameworkCoreException
     * @throws Throwable
     */
    public function getSolrConnectionsByItemReturnsProperItemInNestedSite(): void
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->writeDefaultSolrTestSiteConfigurationForHostAndPort();
        $this->importDataSetFromFixture('can_index_with_multiple_sites.xml');
        $result = $this->addToQueueAndIndexRecord('pages', 1);
        self::assertTrue($result, 'Indexing was not indicated to be successful');
        $result = $this->addToQueueAndIndexRecord('pages', 111);
        self::assertTrue($result, 'Indexing was not indicated to be successful');
        $result = $this->addToQueueAndIndexRecord('pages', 120);
        self::assertTrue($result, 'Indexing was not indicated to be successful');
        $this->waitToBeVisibleInSolr();
        $solrContentJson = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $solrContent = json_decode($solrContentJson, true);
        $solrContentResponse = $solrContent['response'];
        self::assertArrayHasKey('docs', $solrContentResponse, 'Did not find docs in solr response');

        $solrDocs = $solrContentResponse['docs'];
        self::assertCount(3, $solrDocs, 'Could not found index document into solr');

        $sites = array_column($solrDocs, 'site');
        self::assertEquals('testone.site', $sites[0]);
        self::assertEquals('testtwo.site', $sites[1]);
        self::assertEquals('testtwo.site', $sites[2]);
    }
}
