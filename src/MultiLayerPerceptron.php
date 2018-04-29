<?php

namespace Rubix\Engine;

use MathPHP\Statistics\Average;
use Rubix\Engine\NeuralNet\Input;
use Rubix\Engine\Metrics\Accuracy;
use Rubix\Engine\NeuralNet\Hidden;
use Rubix\Engine\NeuralNet\Network;
use Rubix\Engine\Datasets\Supervised;
use Rubix\Engine\Metrics\Classification;
use Rubix\Engine\Persisters\Persistable;
use Rubix\Engine\NeuralNet\LearningRates\Adam;
use Rubix\Engine\NeuralNet\LearningRates\LearningRate;
use InvalidArgumentException;
use RuntimeException;
use SplObjectStorage;

class MultiLayerPerceptron extends Network implements Estimator, Classifier, Persistable
{
    /**
     * The number of training samples to consider per iteration of gradient descent.
     *
     * @var int
     */
    protected $batchSize;

    /**
     * The learnign rate to use when adjusting the weights of the synapses.
     *
     * @var \Rubix\Engine\NeuralNet\LearningRates\LearningRate
     */
    protected $rate;

    /**
     * The minimum validation score needed to early stop training.
     *
     * @var float
     */
    protected $threshold;

    /**
     * The training window to consider during early stop checking i.e. the last
     * n epochs.
     *
     * @var int
     */
    protected $window;

    /**
     * The classification metric used to validate the performance of the model.
     *
     * @var \Rubix\Engine\Metrics\Classification
     */
    protected $metric;

    /**
     * The maximum number of training epochs. i.e. the number of times to iterate
     * over the entire training set before the algorithm terminates.
     *
     * @var int
     */
    protected $epochs;

    /**
     * The validation score of each epoch during training.
     *
     * @param array
     */
    protected $progress = [
        //
    ];

    /**
     * @param  int  $inputs
     * @param  array  $hidden
     * @param  array  $outcomes
     * @param  int  $batchSize
     * @param  \Rubix\Engine\NeuralNet\LearningRates\LearningRate|null  $rate
     * @param  float  $threshold
     * @param  int  $window
     * @param \Rubi\Engine\Metrics\Classification|null  $metric
     * @param  int  $epochs
     * @throws \InvalidArgumentException
     * @return void
     */
    public function __construct(int $inputs, array $hidden, array $outcomes,
                    int $batchSize = 10, LearningRate $rate = null, float $threshold = 0.999,
                    int $window = 3, Classification $metric = null, int $epochs = PHP_INT_MAX)
    {
        if ($epochs < 1) {
            throw new InvalidArgumentException('Epoch parameter must be greater than 0.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size cannot be less than 1.');
        }

        if ($threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('Early stopping threshold parameter must be between 0 and 1.');
        }

        if ($window < 1) {
            throw new InvalidArgumentException('Early stopping window must be 2 epochs or more.');
        }

        if (!isset($rate)) {
            $rate = new Adam();
        }

        if (!isset($metric)) {
            $metric = new Accuracy();
        }

        $this->epochs = $epochs;
        $this->batchSize = $batchSize;
        $this->rate = $rate;
        $this->threshold = $threshold;
        $this->window = $window;
        $this->metric = $metric;

        parent::__construct($inputs, $hidden, $outcomes);
    }

    /**
     * @return array
     */
    public function progress() : array
    {
        return $this->progress;
    }

    /**
     * Train the network using mini-batch gradient descent with backpropagation.
     *
     * @param  \Rubix\Engine\Datasets\Supervised  $dataset
     * @throws \InvalidArgumentException
     * @return void
     */
    public function train(Supervised $dataset) : void
    {
        if (in_array(self::CATEGORICAL, $dataset->columnTypes())) {
            throw new InvalidArgumentException('This estimator only works with continuous samples.');
        }

        $this->randomizeWeights();

        $this->progress = [];

        for ($epoch = 1; $epoch <= $this->epochs; $epoch++) {
            $data = clone $dataset;

            list($training, $testing) = $data->split(0.8);

            foreach ($this->generateMiniBatches($training) as $batch) {
                $sigmas = new SplObjectStorage();

                foreach ($batch as $row => $sample) {
                    $this->feed($sample);

                    $this->backpropagate($sigmas, $batch->getOutcome($row));
                }

                foreach ($sigmas as $synapse) {
                    $synapse->adjustWeight($this->rate->step($synapse, $sigmas[$synapse]));
                }
            }

            $this->progress[] = $this->scoreEpoch($testing);

            if ($epoch >= $this->window) {
                $window = array_slice($this->progress, -$this->window);

                if ((array_sum($window) / $this->window) > $this->threshold) {
                    break 1;
                }

                $worst = $window;
                rsort($worst);

                if ($window === $worst) {
                    break 1;
                }
            }
        }
    }

    /**
     * Feed a sample through the network and make a prediction based on the highest
     * activated output neuron.
     *
     * @param  array  $sample
     * @return \Rubix\Engine\Prediction
     */
    public function predict(array $sample) : Prediction
    {
        $activations = $this->feed($sample);

        $best = ['activation' => -INF, 'outcome' => null];

        foreach ($activations as $outcome => $activation) {
            if ($activation > $best['activation']) {
                $best = [
                    'activation' => $activation,
                    'outcome' => $outcome,
                ];
            }
        }

        return new Prediction($best['outcome'], [
            'activation' => $best['activation'],
        ]);
    }

    /**
     * Feed a sample through the network and calculate the output of each neuron.
     *
     * @param  array  $sample
     * @throws \RuntimeException
     * @return array
     */
    public function feed(array $sample) : array
    {
        if (count($sample) !== count($this->layers[0]) - 1) {
            throw new RuntimeException('The ratio of feature columns to input neurons is unequal, '
                . (string) count($sample) . ' found, ' . (string) (count($this->layers[0]) - 1) . ' needed.');
        }

        $this->reset();

        $activations = [];
        $column = 0;

        foreach ($this->inputs() as $input) {
            if ($input instanceof Input) {
                $input->prime($sample[$column++]);
            }
        }

        foreach ($this->outputs() as $output) {
            $activations[$output->outcome()] = $output->fire();
        }

        return $activations;
    }

    /**
     * Backpropgate the error through the network and return the sums of the partial
     * derivatives for each parameter.
     *
     * @param  \SplObjectStorage  $sigmas
     * @param  mixed  $outcome
     * @return void
     */
    protected function backpropagate(SplObjectStorage $sigmas, $outcome) : void
    {
        for ($layer = count($this->layers) - 1; $layer > 0; $layer--) {
            $gradients = new SplObjectStorage();

            foreach ($this->layers[$layer] as $neuron) {
                if ($neuron instanceof Hidden) {
                    if ($layer === count($this->layers) - 1) {
                        $expected = $neuron->outcome() === $outcome ? 1.0 : 0.0;

                        $gradient = $neuron->derivative() * ($expected - $neuron->output());
                    } else {
                        $previousGradient = 0.0;

                        foreach ($previousGradients as $previousNeuron) {
                            foreach ($previousNeuron->synapses() as $synapse) {
                                if ($synapse->neuron() === $neuron) {
                                    $previousGradient += $synapse->weight() * $previousGradients[$previousNeuron];
                                }
                            }
                        }

                        $gradient = $neuron->derivative() * $previousGradient;
                    }

                    $gradients->attach($neuron, $gradient);

                    foreach ($neuron->synapses() as $synapse) {
                        $sigma = $gradient * $synapse->neuron()->output();

                        if ($sigmas->contains($synapse)) {
                            $sigmas[$synapse] += $sigma;
                        } else {
                            $sigmas->attach($synapse, $sigma);
                        }
                    }
                }
            }

            $previousGradients = $gradients;
        }
    }

    /**
     * Generate a collection of mini batches from the training data.
     *
     * @param  \Rubix\Engine\Datasets\Supervised  $dataset
     * @return array
     */
    protected function generateMiniBatches(Supervised $dataset) : array
    {
        $dataset->randomize();

        $batches = [];

        while (!$dataset->isEmpty()) {
            $batches[] = $dataset->take($this->batchSize);
        }

        return $batches;
    }

    /**
     * Score the training round with supplied classification metric.
     *
     * @param  \Rubix\Engine\Dataset\Supervised  $dataset
     * @return float
     */
    protected function scoreEpoch(Supervised $dataset) : float
    {
        $predictions = array_map(function ($sample) {
            return $this->predict($sample)->outcome();
        }, $dataset->samples());

        return $this->metric->score($predictions, $dataset->outcomes());
    }
}
