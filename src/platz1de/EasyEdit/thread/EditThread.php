<?php
/**
 * pthreads returns null for undefined properties, so we have to use normal ones
 * @noinspection PhpMissingFieldTypeInspection
 */

namespace platz1de\EasyEdit\thread;

use platz1de\EasyEdit\EasyEdit;
use platz1de\EasyEdit\task\queued\QueuedEditTask;
use platz1de\EasyEdit\thread\input\InputData;
use platz1de\EasyEdit\thread\output\OutputData;
use platz1de\EasyEdit\utils\ExtendedBinaryStream;
use pocketmine\thread\Thread;
use ThreadedLogger;
use Throwable;

class EditThread extends Thread
{
	public const STATUS_IDLE = 0;
	public const STATUS_PREPARING = 1;
	public const STATUS_RUNNING = 2;
	public const STATUS_CRASHED = 3;

	/**
	 * @var ThreadedLogger
	 */
	private $logger;
	private static EditThread $instance;
	private int $status = self::STATUS_IDLE;
	private float $lastResponse = 0.0;
	private string $inputData = "";
	private string $outputData = "";

	/**
	 * EditThread constructor.
	 * @param ThreadedLogger $logger
	 */
	public function __construct(ThreadedLogger $logger)
	{
		self::$instance = $this;
		$this->logger = $logger;
	}


	public function onRun(): void
	{
		gc_enable();

		$this->getLogger()->debug("Started EditThread");

		$this->lastResponse = microtime(true);

		$sleep = 0;
		while (!$this->isKilled) {
			$this->parseInput();
			if ($this->getStatus() !== self::STATUS_CRASHED) {
				$task = ThreadData::getNextTask();
				ThreadData::setTask($task);
				if ($task === null) {
					$this->synchronized(function (): void {
						$this->wait();
					});
				} else {
					try {
						$task->execute();
						$this->tick($task);
					} catch (Throwable $throwable) {
						$this->logger->logException($throwable);
						$this->setStatus(self::STATUS_CRASHED);
						$sleep = time() + 9;
					}
				}
			} else {
				$this->synchronized(function (): void {
					$this->wait(10 * 1000 * 1000);
				});
				if($sleep < time()){
					$this->setStatus(self::STATUS_IDLE);
				}
			}
		}
	}

	private function tick(QueuedEditTask $task): void
	{
		while (!$task->continue()) {
			$this->synchronized(function (): void {
				$this->wait();
			});
			$this->parseInput();
		}
	}

	/**
	 * @return ThreadedLogger
	 */
	public function getLogger(): ThreadedLogger
	{
		return $this->logger;
	}

	/**
	 * @return EditThread
	 */
	public static function getInstance(): EditThread
	{
		$thread = self::getCurrentThread();
		if (!$thread instanceof self) {
			return self::$instance;
		}
		return $thread;
	}

	/**
	 * @return string
	 */
	public function getThreadName(): string
	{
		return "EasyEdit editing";
	}

	/**
	 * @return bool
	 */
	public function isRunning(): bool
	{
		return $this->getStatus() === self::STATUS_RUNNING;
	}

	/**
	 * @return int
	 */
	public function getStatus(): int
	{
		return $this->status;
	}

	/**
	 * @param int $status
	 * @internal
	 */
	public function setStatus(int $status): void
	{
		$this->synchronized(function () use ($status): void {
			$this->status = $status;
			$this->lastResponse = microtime(true);
		});
	}

	//TODO: Implement proper callbacks

	/**
	 * @return float
	 */
	public function getLastResponse(): float
	{
		return $this->getStatus() === self::STATUS_IDLE ? microtime(true) : $this->lastResponse;
	}

	private function parseInput(): void
	{
		if ($this->inputData !== "") {
			$stream = new ExtendedBinaryStream($this->inputData);
			$this->synchronized(function (): void {
				$this->inputData = "";
			});

			while (!$stream->feof()) {
				$data = InputData::fastDeserialize($stream->getString());
				$this->getLogger()->debug("Received " . $data::class);
				$data->handle();
			}

		}
	}

	public function parseOutput(): void
	{
		if ($this->outputData !== "") {
			$stream = new ExtendedBinaryStream($this->outputData);

			while (!$stream->feof()) {
				$data = OutputData::fastDeserialize($stream->getString());
				EasyEdit::getInstance()->getLogger()->debug("Received " . $data::class);
				$data->handle();
			}

			$this->synchronized(function (): void {
				$this->outputData = "";
			});
		}
	}

	/**
	 * @param InputData $data
	 */
	public function sendToThread(InputData $data): void
	{
		$stream = new ExtendedBinaryStream($this->inputData);

		$stream->putString($data->fastSerialize());

		$input = $stream->getBuffer();
		$this->synchronized(function () use ($input): void {
			$this->inputData = $input;

			$this->notify();
		});
	}

	/**
	 * @param OutputData $data
	 * @internal
	 */
	public function sendOutput(OutputData $data): void
	{
		$stream = new ExtendedBinaryStream($this->outputData);

		$stream->putString($data->fastSerialize());

		$output = $stream->getBuffer();
		$this->synchronized(function () use ($output): void {
			$this->outputData = $output;
		});
	}
}