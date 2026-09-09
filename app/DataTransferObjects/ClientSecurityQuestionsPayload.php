<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

final readonly class ClientSecurityQuestionsPayload
{
    /**
     * @param  list<array{question: string}>  $questions
     */
    public function __construct(
        public bool $configure,
        public array $questions,
    ) {}

    public static function from(mixed $resource): self
    {
        if ($resource instanceof self) {
            return $resource;
        }

        return self::fromArray(is_array($resource) ? $resource : []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $questions = [];
        if (is_array($data['questions'] ?? null)) {
            foreach ($data['questions'] as $item) {
                if (is_array($item) && isset($item['question']) && is_string($item['question'])) {
                    $questions[] = ['question' => $item['question']];
                }
            }
        }

        return new self(
            configure: (bool) ($data['configure'] ?? false),
            questions: $questions,
        );
    }

    /**
     * @return array{configure: bool, questions: list<array{question: string}>}
     */
    public function toArray(): array
    {
        return [
            'configure' => $this->configure,
            'questions' => $this->questions,
        ];
    }
}
