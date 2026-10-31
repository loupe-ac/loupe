<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Work on one subject, such as a card, that one bridge claims. The outbox
 * carries it to the bridges, and the heartbeat reply offers it again while it
 * stays open. The subject id is a plain value, so this module needs nothing
 * from the board.
 */
#[ORM\Entity(repositoryClass: WorkRequestRepository::class)]
// The heartbeat reply reads the open requests of the projects a bridge follows.
#[ORM\Index(name: 'idx_work_requests_project_state', columns: ['project_id', 'state'])]
#[ORM\Index(name: 'idx_work_requests_state_lease', columns: ['state', 'lease_until'])]
#[ORM\Index(name: 'idx_work_requests_inbox_action', columns: ['inbox_action_id'])]
// The workflow engine reads the requests of one card in every state.
#[ORM\Index(name: 'idx_work_requests_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Table(name: 'work_requests')]
// One subject holds one live request of a kind. The predicate is written the
// way Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: self::LIVE_SUBJECT_KIND_INDEX, columns: ['subject_type', 'subject_id', 'kind'], options: ['where' => "((state)::text = ANY (ARRAY[('open'::character varying)::text, ('claimed'::character varying)::text]))"])]
class WorkRequest
{
    public const string LIVE_SUBJECT_KIND_INDEX = 'uniq_work_request_live_subject_kind';

    public const int MAX_KIND_LENGTH = 40;

    public const int MAX_RULE_ID_LENGTH = 100;

    /** The kind of a request that opens an interactive session for a person. */
    public const string SESSION_KIND = 'session';

    /** The id of the template rule that opened the request, or a person-work id such as inbox:start-session. */
    public const string RULE_ID_PATTERN = '/^[a-z0-9][a-z0-9._:-]{0,99}$/D';

    public const int MAX_REASON_LENGTH = 64;

    /** The wait of a request that a usage limit handed back. */
    public const string WAIT_USAGE_LIMIT = 'usage-limit';

    /** The withdraw reasons of an open request that no online bridge could take. */
    public const string REASON_NO_TAKER = 'no-taker';
    public const string REASON_NO_CAPABLE_TAKER = 'no-capable-taker';
    public const string REASON_NO_CAPABLE_TAKER_APP_PROMPTS = 'no-capable-taker-app-prompts';

    /** A reason code, never text a person wrote. */
    public const string REASON_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';

    public const int MAX_BRANCH_LENGTH = 200;

    public const int MAX_MODEL_LENGTH = 64;

    /** One word for claude --model, as the bridge takes it. A leading hyphen would read as an option. */
    public const string MODEL_PATTERN = '/^[^\s\p{Z}\p{Cc}-][^\s\p{Z}\p{Cc}]{0,63}$/uD';

    /** The levels claude --effort takes. */
    public const array EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(name: 'state', length: 20, enumType: WorkRequestState::class)]
    public WorkRequestState $state = WorkRequestState::Open;

    /** The bridge that holds the claim, or that held it when the request settled. */
    #[ORM\Column(name: 'bridge_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $bridgeId = null;

    /** The fencing token of the current claim. A bridge proves its claim with it. */
    #[ORM\Column(name: 'claim_token', type: UuidType::NAME, nullable: true)]
    public ?Uuid $claimToken = null;

    #[ORM\Column(name: 'lease_until', nullable: true)]
    public ?\DateTimeImmutable $leaseUntil = null;

    /** How many times a bridge claimed the request. */
    #[ORM\Column(name: 'claims', options: ['default' => 0])]
    public int $claims = 0;

    /** When a lapsed claim last opened the request again, or a release of its card or the end of a project hold restarted its timeout. */
    #[ORM\Column(name: 'reopened_at', nullable: true)]
    public ?\DateTimeImmutable $reopenedAt = null;

    /** When the hold of the project found the request open, or saw it open. The end of the hold restarts its timeout and clears this. */
    #[ORM\Column(name: 'work_hold_since', nullable: true)]
    public ?\DateTimeImmutable $workHoldSince = null;

    #[ORM\Column(name: 'settled_at', nullable: true)]
    public ?\DateTimeImmutable $settledAt = null;

    /** The session of an unfinished run of the card and kind, which the bridge resumes. Null for a fresh start. */
    #[ORM\Column(name: 'resume_session_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $resumeSessionId = null;

    /** Why an open request waits, such as usage-limit. Null for a request that waits for nothing. */
    #[ORM\Column(name: 'wait_cause', length: 20, nullable: true)]
    public ?string $waitCause = null;

    /** The account whose limit holds the request. Null when the wait has no account. */
    #[ORM\Column(name: 'wait_account', length: 40, nullable: true)]
    public ?string $waitAccount = null;

    /** No bridge may claim the request before this time. Null for a request that waits on no clock. */
    #[ORM\Column(name: 'wait_until', nullable: true)]
    public ?\DateTimeImmutable $waitUntil = null;

    #[ORM\Column(name: 'reason', length: self::MAX_REASON_LENGTH, nullable: true)]
    public ?string $reason = null;

    /** The model the run asks for over the model of the bridge's work entry. */
    #[ORM\Column(name: 'model', length: self::MAX_MODEL_LENGTH, nullable: true)]
    public ?string $model = null;

    /** The effort level the run asks for. */
    #[ORM\Column(name: 'effort', length: 16, nullable: true)]
    public ?string $effort = null;

    /** The text of the app prompt the bridge gives the worker, or null when the rule names none. */
    #[ORM\Column(name: 'prompt', type: Types::TEXT, nullable: true)]
    public ?string $prompt = null;

    /** The inbox action that asked for the request, or null for a request of the workflow. No foreign key: the inbox owns the row. */
    #[ORM\Column(name: 'inbox_action_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $inboxActionId = null;

    /** The number of the inbox item that the request came from, a label a person reads. */
    #[ORM\Column(name: 'inbox_item_number', nullable: true)]
    public ?int $inboxItemNumber = null;

    /** The inbox item that the action belongs to, so the worker can read it with inbox_get. */
    #[ORM\Column(name: 'inbox_item_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $inboxItemId = null;

    /** The one bridge that may take the request, or null when any bridge that can run it may. No foreign key: a heartbeat replaces the bridge row. */
    #[ORM\Column(name: 'target_bridge_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $targetBridgeId = null;

    /** The git branch whose worktree a command entry runs in, or null for the work of a card. */
    #[ORM\Column(name: 'branch', length: self::MAX_BRANCH_LENGTH, nullable: true)]
    public ?string $branch = null;

    /**
     * The parameter values of a command entry, by parameter name. Null when the request carries none.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(name: 'params', type: Types::JSON, nullable: true)]
    public ?array $params = null;

    /**
     * The run list of a command entry, with its placeholders, as the owner confirmed it. The bridge refuses the request when its entry differs.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'run', type: Types::JSON, nullable: true)]
    public ?array $run = null;

    /** What the work does, such as code-review, whatever its kind. Null for a request with no role. */
    #[ORM\Column(name: 'role', length: 20, nullable: true)]
    public ?string $role = null;

    /** What the card held when the request opened. A row from before the column reads as an empty context. */
    public WorkRequestContext $context {
        get => WorkRequestContext::fromArray($this->contextData);
        set {
            $this->contextData = $value->toArray();
        }
    }

    /** @var array<mixed>|null */
    #[ORM\Column(name: 'context', type: Types::JSON, nullable: true)]
    private ?array $contextData = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        #[ORM\Column(name: 'subject_type', length: WorkSubject::MAX_TYPE_LENGTH)]
        public string $subjectType,

        #[ORM\Column(name: 'subject_id', type: UuidType::NAME)]
        public Uuid $subjectId,

        /** The card number a person sees, for a card subject. A label, never an id. */
        #[ORM\Column(name: 'card_number', nullable: true)]
        public ?int $cardNumber,

        #[ORM\Column(name: 'kind', length: self::MAX_KIND_LENGTH)]
        public string $kind,

        /** The capability a bridge must report to take the request. Null when any bridge that takes work requests can. */
        #[ORM\Column(name: 'capability', length: 40, nullable: true)]
        public ?string $capability,

        #[ORM\Column(name: 'rule_id', length: self::MAX_RULE_ID_LENGTH)]
        public string $ruleId,

        #[ORM\Column(name: 'created_at')]
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /** The rules of `git check-ref-format --branch`, a little stricter than git where that is cheap. */
    public static function isBranchName(string $branch): bool
    {
        return '' !== $branch
            && '@' !== $branch
            && mb_strlen($branch) <= self::MAX_BRANCH_LENGTH
            && 1 !== preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $branch)
            && 1 !== preg_match('#(?:^|/)\.|\.lock(?:/|$)#', $branch)
            && !str_contains($branch, '..')
            && !str_contains($branch, '//')
            && !str_contains($branch, '@{')
            && !str_starts_with($branch, '-')
            && !str_starts_with($branch, '/')
            && !str_ends_with($branch, '/')
            && !str_ends_with($branch, '.');
    }

    public function subject(): WorkSubject
    {
        return new WorkSubject($this->subjectType, $this->subjectId);
    }

    /** Moves a claimed request to done or refused. Answers false when the request is not claimed. */
    public function settle(WorkRequestState $state, ?string $reason, \DateTimeImmutable $now): bool
    {
        if (WorkRequestState::Done !== $state && WorkRequestState::Refused !== $state) {
            throw new \LogicException('A work request settles to done or refused.');
        }
        if (null !== $reason && 1 !== preg_match(self::REASON_PATTERN, $reason)) {
            throw new \LogicException('A work request reason is a code.');
        }
        if (WorkRequestState::Claimed !== $this->state) {
            return false;
        }

        $this->state = $state;
        $this->reason = $reason;
        $this->settledAt = $now;

        return true;
    }

    /**
     * Opens a claimed request again for a usage limit. The claim goes, and the
     * request waits on the account instead of a clock. A run that did work binds
     * the request to its bridge, because the session and the changes live there.
     * Answers false when the request is not claimed.
     */
    public function handBackForUsageLimit(string $account, ?Uuid $sessionId, bool $bindToBridge, \DateTimeImmutable $now): bool
    {
        if (WorkRequestState::Claimed !== $this->state) {
            return false;
        }

        if ($bindToBridge) {
            $this->targetBridgeId = $this->bridgeId;
        }
        $this->state = WorkRequestState::Open;
        $this->bridgeId = null;
        $this->claimToken = null;
        $this->leaseUntil = null;
        $this->reopenedAt = $now;
        $this->resumeSessionId = $sessionId;
        $this->waitCause = self::WAIT_USAGE_LIMIT;
        $this->waitAccount = $account;
        $this->waitUntil = null;

        return true;
    }

    /** Moves a live request to cancelled or expired, with the reason code of the withdrawal when the caller has one. Answers false when the request already settled. */
    public function withdraw(WorkRequestState $state, \DateTimeImmutable $now, ?string $reason = null): bool
    {
        if (null !== $reason && 1 !== preg_match(self::REASON_PATTERN, $reason)) {
            throw new \LogicException('A work request reason is a code.');
        }
        if (WorkRequestState::Cancelled !== $state && WorkRequestState::Expired !== $state) {
            throw new \LogicException('A work request withdraws to cancelled or expired.');
        }
        if (!$this->state->isLive()) {
            return false;
        }

        $this->state = $state;
        $this->reason = $reason;
        $this->settledAt = $now;

        return true;
    }
}
