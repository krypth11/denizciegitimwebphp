<?php

interface PusulaAiClientInterface
{
    public function testConnection(): array;

    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @param array<string, mixed> $options
     * @return array{success:bool, reply:string, input_tokens:int, output_tokens:int, raw:mixed, error_code?:string, error_message?:string}
     */
    public function generateChatReply(array $messages, array $options = []): array;
}
