<?php

declare(strict_types=1);

namespace Kaly\Text;

use Exception;
use Kaly\Util\Request;

/**
 * A simple ollama client
 */
class Ollama
{
    final public const BASE_URL = 'http://localhost:11434';
    final public const BASE_MODEL = 'llama3.2';

    protected ?string $model;
    protected ?string $url;

    public function __construct(?string $model = null, ?string $url = null)
    {
        $this->model = $model ?? self::BASE_MODEL;
        $this->url = $url ?? self::BASE_URL;
    }

    /**
     * @param null|array<int> $context
     * @return array{model:string,created_at:string,response:string,done:bool,done_reason:string,context:array<int>,total_duration:int,load_duration:int,prompt_eval_count:int,prompt_eval_duration:int,eval_count:int,eval_duration:int}
     */
    public function generate(string $prompt, ?array $context = null)
    {
        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ];
        if (!empty($context)) {
            $data['context'] = $context;
        }
        $result = Request::make($this->url . '/api/generate', 'POST', data: $data, json: true);

        $decoded = json_decode($result, true);

        if (!$decoded) {
            throw new Exception("Failed to decode json: " . json_last_error_msg());
        }

        //@phpstan-ignore-next-line
        return $decoded;
    }
}
