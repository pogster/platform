<?php declare(strict_types=1);

namespace Shopware\Core\Content\Sitemap\Service;

use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Content\Sitemap\Exception\AlreadyLockedException;
use Shopware\Core\Content\Sitemap\Exception\UrlProviderNotFound;
use Shopware\Core\Content\Sitemap\Provider\UrlProviderInterface;
use Shopware\Core\Content\Sitemap\Struct\SitemapGenerationResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use function sprintf;

class SitemapExporter implements SitemapExporterInterface
{
    /**
     * @var SitemapWriterInterface
     */
    private $sitemapWriter;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var array|\IteratorAggregate
     */
    private $urlProvider;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @var int
     */
    private $batchSize;

    /**
     * @param \IteratorAggregate<int, UrlProviderInterface> $urlProvider
     */
    public function __construct(
        SitemapWriterInterface $sitemapWriter,
        SystemConfigService $systemConfigService,
        \IteratorAggregate $urlProvider,
        CacheItemPoolInterface $cache,
        int $batchSize
    ) {
        $this->sitemapWriter = $sitemapWriter;
        $this->systemConfigService = $systemConfigService;
        $this->urlProvider = $urlProvider;
        $this->cache = $cache;
        $this->batchSize = $batchSize;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(SalesChannelContext $salesChannelContext, bool $force = false, ?string $lastProvider = null, ?int $offset = null): SitemapGenerationResult
    {
        if ($force === false && $this->isLocked($salesChannelContext)) {
            throw new AlreadyLockedException($salesChannelContext);
        }

        /** @var UrlProviderInterface $urlProvider */
        $urlProvider = $this->getUrlProvider($lastProvider);
        $urlResult = $urlProvider->getUrls($salesChannelContext, $this->batchSize, $offset);

        $fileName = $this->getFileName($urlProvider, $offset);
        if ($offset === null || $offset === 0) {
            $fileHandle = $this->sitemapWriter->createFile($fileName);
        } else {
            $fileHandle = $this->sitemapWriter->openFile($fileName);
        }

        $this->sitemapWriter->writeUrlsToFile($urlResult->getUrls(), $fileHandle);

        if ($urlResult->getNextOffset() !== null) {
            $this->sitemapWriter->closeFile($fileHandle);

            $lastProvider = $urlProvider->getName();
        } else {
            // this sitemap is finished
            $this->sitemapWriter->finishFile($fileHandle);

            $this->sitemapWriter->moveFile($fileName, $salesChannelContext);

            $nextProvider = $this->getNextUrlProvider($urlProvider->getName());

            $lastProvider = $nextProvider ? $nextProvider->getName() : null;
        }

        $finish = $lastProvider === null;
        if ($finish) {
            $this->lock($salesChannelContext);
        }

        return new SitemapGenerationResult($finish, $lastProvider, $urlResult->getNextOffset() ?: 0);
    }

    private function getFileName(UrlProviderInterface $urlProvider, ?int $offset): string
    {
        if ($offset) {
            $i = floor((($offset * $this->batchSize) / SitemapExporterInterface::SITEMAP_URL_LIMIT) + 1);
        } else {
            $i = 1;
        }

        return sprintf('sitemap-%s-%d.xml.gz', $urlProvider->getName(), $i);
    }

    private function getUrlProvider(?string $provider): ?UrlProviderInterface
    {
        if ($provider === null) {
            return $this->getNextUrlProvider($provider);
        }

        /** @var UrlProviderInterface $urlProvider */
        foreach ($this->urlProvider as $urlProvider) {
            if ($urlProvider->getName() === $provider) {
                return $urlProvider;
            }
        }

        throw new UrlProviderNotFound($provider);
    }

    private function getNextUrlProvider(?string $lastProvider): ?UrlProviderInterface
    {
        if ($lastProvider === null) {
            foreach ($this->urlProvider as $urlProvider) {
                return $urlProvider;
            }
        }

        $getNext = false;
        /** @var UrlProviderInterface $urlProvider */
        foreach ($this->urlProvider as $urlProvider) {
            if ($getNext === true) {
                return $urlProvider;
            }

            if ($urlProvider->getName() === $lastProvider) {
                $getNext = true;
            }
        }

        return null;
    }

    private function lock(SalesChannelContext $salesChannelContext): bool
    {
        $cacheKey = $this->generateCacheKeyForSalesChannel($salesChannelContext);
        if ($this->cache->hasItem($cacheKey)) {
            return false;
        }

        $lifeTime = (int) $this->systemConfigService->get('core.sitemap.sitemapRefreshTime');

        $lock = $this->cache->getItem($cacheKey);
        $lock->set(sprintf('Locked: %s', (new \DateTime('NOW', new \DateTimeZone('UTC')))->format(\DateTime::ATOM)))
            ->expiresAfter($lifeTime);

        return $this->cache->save($lock);
    }

    private function isLocked(SalesChannelContext $salesChannelContext): bool
    {
        return $this->cache->hasItem($this->generateCacheKeyForSalesChannel($salesChannelContext));
    }

    private function generateCacheKeyForSalesChannel(SalesChannelContext $salesChannelContext): string
    {
        return sprintf('sitemap-exporter-running-%s-%s', $salesChannelContext->getSalesChannel()->getId(), $salesChannelContext->getSalesChannel()->getLanguageId());
    }
}
