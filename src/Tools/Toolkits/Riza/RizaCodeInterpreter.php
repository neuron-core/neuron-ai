<?php

namespace NeuronAI\Tools\Toolkits\Riza;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

class RizaCodeInterpreter extends Tool
{
    protected Client $client;

    protected string $url = 'https://api.riza.io/v1/';

    public function __construct(
        protected string $key,
        protected string $language = 'PHP',
    ) {
        parent::__construct(
            "execute_{$language}_code",
            "Execute {$language} scripts in a secure and isolated runtime environment."
        );

        $this->addProperty(
            new ToolProperty(
                'code',
                PropertyType::STRING,
                'The code to execute.',
                true,
            )
        )->addProperty(
            new ToolProperty(
                'args',
                PropertyType::ARRAY,
                "List of command line arguments to pass to the script (List of strings).",
                false,
            )
        )->addProperty(
            new ToolProperty(
                'env',
                PropertyType::ARRAY,
                "Set of key-value pairs to add to the script's execution environment.",
                false,
            )
        )->setCallable($this);
    }

    public function getClient(): Client
    {
        if (isset($this->client)) {
            return $this->client;
        }

        return $this->client = new Client([
            'base_uri' => trim($this->url, '/').'/',
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json',
            ]
        ]);
    }

    public function __invoke(
        string $code,
        array $args = [],
        array $env = [],
    ) {
        $result = $this->getClient()->post('execute', [
            RequestOptions::JSON => [
                'language' => $this->language,
                'code' => $code,
                'args' => $args,
                'env' => $env,
            ]
        ])->getBody()->getContents();

        return \json_decode($result, true);
    }
}
