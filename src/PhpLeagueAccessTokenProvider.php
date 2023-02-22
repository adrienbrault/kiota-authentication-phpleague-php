<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Authentication;


use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Http\Promise\RejectedPromise;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Microsoft\Kiota\Abstractions\Authentication\AccessTokenProvider;
use Microsoft\Kiota\Abstractions\Authentication\AllowedHostsValidator;
use Microsoft\Kiota\Authentication\Oauth\ProviderFactory;
use Microsoft\Kiota\Authentication\Oauth\TokenRequestContext;

/**
 * Class PhpLeagueAccessTokenProvider
 * @package Microsoft\Kiota\Authentication
 * @copyright 2022 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class PhpLeagueAccessTokenProvider implements AccessTokenProvider
{
    /**
     * @var TokenRequestContext {@link TokenRequestContext}
     */
    private TokenRequestContext $tokenRequestContext;
    /**
     * @var AllowedHostsValidator Validates whether a token should be fetched for a request url
     */
    private AllowedHostsValidator $allowedHostsValidator;
    /**
     * @var array<string, string>
     */
    private array $scopes;
    /**
     * @var array<string, object>
     */
    private array $collaborators;
    /**
     * @var AccessToken|null Token object to re-use before expiry
     */
    private ?AccessToken $cachedToken = null;
    /**
     * @var GenericProvider OAuth 2.0 provider from PHP League library
     */
    private GenericProvider $oauthProvider;

    /**
     * Creates a new instance
     * @param TokenRequestContext $tokenRequestContext
     * @param array $scopes
     * @param array $allowedHosts
     */
    public function __construct(TokenRequestContext $tokenRequestContext, array $scopes = [], array $allowedHosts = [], ?GenericProvider $oauthProvider = null)
    {
        $this->tokenRequestContext = $tokenRequestContext;
        if (empty($scopes)) {
            $scopes = ['https://graph.microsoft.com/.default'];
        }
        $this->scopes = $scopes;

        $this->allowedHostsValidator = new AllowedHostsValidator();
        if (empty($allowedHosts)) {
            $this->allowedHostsValidator->setAllowedHosts(["graph.microsoft.com", "graph.microsoft.us", "dod-graph.microsoft.us", "graph.microsoft.de", "microsoftgraph.chinacloudapi.cn", "canary.graph.microsoft.com"]);
        } else {
            $this->allowedHostsValidator->setAllowedHosts($allowedHosts);
        }

        $this->oauthProvider = $oauthProvider ?? ProviderFactory::create($tokenRequestContext);
    }

    /**
     * @inheritDoc
     */
    public function getAuthorizationTokenAsync(string $url): Promise
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' || !$this->getAllowedHostsValidator()->isUrlHostValid($url)) {
            return new FulfilledPromise(null);
        }
        try {
            $params = array_merge($this->tokenRequestContext->getParams(), ['scope' => implode(' ', $this->scopes)]);
            if ($this->cachedToken) {
                if ($this->cachedToken->getExpires() && $this->cachedToken->hasExpired()) {
                    if ($this->cachedToken->getRefreshToken()) {
                        // @phpstan-ignore-next-line
                        $this->cachedToken = $this->oauthProvider->getAccessToken('refresh_token', $this->tokenRequestContext->getRefreshTokenParams($this->cachedToken->getRefreshToken()));
                    } else {
                        // @phpstan-ignore-next-line
                        $this->cachedToken = $this->oauthProvider->getAccessToken($this->tokenRequestContext->getGrantType(), $params);
                    }
                }
                return new FulfilledPromise($this->cachedToken->getToken());
            }
            // @phpstan-ignore-next-line
            $this->cachedToken = $this->oauthProvider->getAccessToken($this->tokenRequestContext->getGrantType(), $params);
            return new FulfilledPromise($this->cachedToken->getToken());
        } catch (\Exception $ex) {
            return new RejectedPromise($ex);
        }
    }

    /**
     * @inheritDoc
     */
    public function getAllowedHostsValidator(): AllowedHostsValidator
    {
        return $this->allowedHostsValidator;
    }

    /**
     * Returns the underlying OAuth provider
     *
     * @return AbstractProvider
     */
    public function getOauthProvider(): AbstractProvider
    {
        return $this->oauthProvider;
    }

}
