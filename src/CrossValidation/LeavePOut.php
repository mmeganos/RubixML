<?php

namespace Rubix\ML\CrossValidation;

use Rubix\ML\Learner;
use Rubix\ML\Deferred;
use Rubix\ML\Parallel;
use Rubix\ML\Estimator;
use Rubix\ML\Backends\Serial;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Other\Helpers\Stats;
use Rubix\ML\Other\Traits\Multiprocessing;
use Rubix\ML\CrossValidation\Metrics\Metric;
use Rubix\ML\Other\Specifications\EstimatorIsCompatibleWithMetric;
use InvalidArgumentException;

/**
 * Leave P Out
 *
 * Leave P Out cross-validation involves using p observations as the validation
 * set and the remaining observations as the training set. This is repeated on
 * all ways to cut the original sample on a validation set of p observations
 * and a training set. The resulting score is an average of all the tests.
 *
 * @category    Machine Learning
 * @package     Rubix/ML
 * @author      Andrew DalPino
 */
class LeavePOut implements Validator, Parallel
{
    use Multiprocessing;

    /**
     * The number of samples to leave out each round for testing.
     *
     * @var int
     */
    protected $p;

    /**
     * @param int $p
     * @throws \InvalidArgumentException
     */
    public function __construct(int $p = 10)
    {
        if ($p < 1) {
            throw new InvalidArgumentException('P cannot be less'
                . "than 1, $p given.");
        }

        $this->p = $p;
        $this->backend = new Serial();
    }

    /**
     * Test the estimator with the supplied dataset and return a score.
     *
     * @param \Rubix\ML\Learner $estimator
     * @param \Rubix\ML\Datasets\Labeled $dataset
     * @param \Rubix\ML\CrossValidation\Metrics\Metric $metric
     * @throws \InvalidArgumentException
     * @return float
     */
    public function test(Learner $estimator, Labeled $dataset, Metric $metric) : float
    {
        EstimatorIsCompatibleWithMetric::check($estimator, $metric);

        $n = (int) round($dataset->numRows() / $this->p);

        $this->backend->flush();

        for ($i = 0; $i < $n; $i++) {
            $training = clone $dataset;

            $testing = $training->splice($i * $this->p, $this->p);

            $this->backend->enqueue(new Deferred(
                [self::class, 'scorer'],
                [$estimator, $training, $testing, $metric]
            ));
        }

        $scores = $this->backend->process();

        return Stats::mean($scores);
    }

    /**
     * Score an estimator on one of p slices of the dataset.
     *
     * @param \Rubix\ML\Learner $estimator
     * @param \Rubix\ML\Datasets\Dataset $training
     * @param \Rubix\ML\Datasets\Labeled $testing
     * @param \Rubix\ML\CrossValidation\Metrics\Metric $metric
     * @return float
     */
    public static function scorer(Learner $estimator, Dataset $training, Labeled $testing, Metric $metric) : float
    {
        $estimator->train($training);

        $predictions = $estimator->predict($testing);

        return $metric->score($predictions, $testing->labels());
    }
}
