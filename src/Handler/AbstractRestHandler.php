<?php
declare(strict_types = 1);

namespace LosMiddleware\ApiServer\Handler;

use LosMiddleware\ApiServer\Entity\Collection;
use LosMiddleware\ApiServer\Entity\Entity;
use LosMiddleware\ApiServer\Entity\EntityInterface;
use LosMiddleware\ApiServer\Exception\MethodNotAllowedException;
use LosMiddleware\ApiServer\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Diactoros\Response\EmptyResponse;
use Zend\Expressive\Hal\HalResponseFactory;
use Zend\Expressive\Hal\Metadata\RouteBasedCollectionMetadata;
use Zend\Expressive\Hal\ResourceGenerator;
use Zend\Expressive\Helper\UrlHelper;
use Zend\InputFilter\InputFilterAwareInterface;
use Zend\ProblemDetails\ProblemDetailsResponseFactory;

abstract class AbstractRestHandler implements RequestHandlerInterface
{
    const IDENTIFIER_NAME = 'id';

    /** @var Entity */
    protected $entityPrototype;
    /** @var Request */
    protected $request;
    /** @var UrlHelper */
    protected $urlHelper;
    /** @var int  */
    protected $itemCountPerPage = 25;
    /** @var ProblemDetailsResponseFactory */
    protected $problemDetailsResponseFactory;
    /** @var ResourceGenerator */
    private $resourceGenerator;
    /** @var HalResponseFactory  */
    private $responseFactory;

    /**
     * AbstractRestAction constructor.
     * @param UrlHelper $urlHelper
     * @param ProblemDetailsResponseFactory $problemDetailsResponseFactory
     * @param ResourceGenerator $resourceGenerator
     * @param HalResponseFactory $responseFactory
     */
    public function __construct(
        UrlHelper $urlHelper,
        ProblemDetailsResponseFactory $problemDetailsResponseFactory,
        ResourceGenerator $resourceGenerator,
        HalResponseFactory $responseFactory
    ) {
        $this->urlHelper = $urlHelper;
        $this->problemDetailsResponseFactory = $problemDetailsResponseFactory;
        $this->resourceGenerator = $resourceGenerator;
        $this->responseFactory = $responseFactory;
    }

    /**
     * Method to return the resource name for collections generation
     *
     * @return string
     */
    public function getResourceName() : string
    {
        $tokens = explode('\\', get_class($this));
        $className = end($tokens);
        return strtolower(str_replace('Handler', '', $className));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $requestMethod = strtoupper($request->getMethod());
        $this->request = $request;

        try {
            return $this->handleMethods($requestMethod);
        } catch (ValidationException $ex) {
            return $this->problemDetailsResponseFactory->createResponse(
                $this->request,
                $ex->getCode(),
                $ex->getMessage(),
                '',
                '',
                ['validation_messages' => $ex->getValidationMessages()]
            );
        }
    }

    protected function handleMethods(string $requestMethod) : Response
    {
        $id = $this->request->getAttribute(static::IDENTIFIER_NAME);

        switch ($requestMethod) {
            case 'GET':
                return isset($id)
                    ? $this->handleFetch($id)
                    : $this->handleFetchAll();
            case 'POST':
                if (isset($id)) {
                    return $this->problemDetailsResponseFactory->createResponse(
                        $this->request,
                        405,
                        'Invalid entity operation POST'
                    );
                }
                return $this->handlePost();
            case 'PUT':
                return isset($id)
                    ? $this->handleUpdate($id)
                    : $this->handleUpdateList();
            case 'PATCH':
                return isset($id)
                    ? $this->handlePatch($id)
                    : $this->handlePatchList();
            case 'DELETE':
                return isset($id)
                    ? $this->handleDelete($id)
                    : $this->handleDeleteList();
            case 'HEAD':
                return $this->head();
            case 'OPTIONS':
                return $this->options();
            default:
                return $this->problemDetailsResponseFactory->createResponse(
                    $this->request,
                    405,
                    'Invalid operation'
                );
        }
    }

    /**
     * Call the inputfilter to filter and validate data
     *
     * @throws ValidationException
     * @return array
     */
    protected function validateBody() : array
    {
        $data = $this->request->getParsedBody();

        if (! is_array($data)) {
            $data = [];
        }

        if ($this->entityPrototype == null || ! ($this->entityPrototype instanceof InputFilterAwareInterface)) {
            return $data;
        }

        if (strtoupper($this->request->getMethod()) == 'PATCH') {
            $this->entityPrototype->getInputFilter()->setValidationGroup(array_keys($data));
        }

        if (! $this->entityPrototype->getInputFilter()->setData($data)->isValid()) {
            throw ValidationException::fromMessages($this->entityPrototype->getInputFilter()->getMessages());
        }

        $values = $this->entityPrototype->getInputFilter()->getValues();

        $parsed = [];
        foreach ($values as $key => $value) {
            if (array_key_exists($key, $data)) {
                $parsed[$key] = $value;
            }
        }
        return $parsed;
    }

    /**
     * Generates a proper response based on the Entity ot Collection
     *
     * @param Entity|Collection $entity
     * @param int $code
     * @throws \InvalidArgumentException
     * @return Response
     */
    protected function generateResponse($entity, $code = 200) : Response
    {
        if ($entity instanceof Entity) {
            $resource = $this->resourceGenerator->fromObject($entity, $this->request);
            return $this->responseFactory->createResponse($this->request, $resource);
        }

        $queryParams = $this->request->getQueryParams();

        $metadataMap = $this->resourceGenerator->getMetadataMap();
        /** @var RouteBasedCollectionMetadata $metadata */
        $metadata = $metadataMap->get(get_class($entity));
        $metadataQuery = $origMetadataQuery = $metadata->getQueryStringArguments();
        foreach ($queryParams as $key => $value) {
            $metadataQuery = array_merge($metadataQuery, [$key => $value]);
        }
        $metadata->setQueryStringArguments($metadataQuery);

        $resource = $this->resourceGenerator->fromObject($entity, $this->request);

        // Reset query string arguments
        $metadata->setQueryStringArguments($origMetadataQuery);

        $response = $this->responseFactory->createResponse($this->request, $resource);

        if ($code !== 200) {
            $response = $response->withStatus($code);
        }

        return $response;
    }

    /**
     * Fetch an Entity
     *
     * @param mixed $id
     * @return Response
     */
    protected function handleFetch($id) : Response
    {
        $entity = $this->fetch($id);
        return $this->generateResponse($entity);
    }

    /**
     * Fetch a collection
     *
     * @return Response
     */
    protected function handleFetchAll() : Response
    {
        $list = $this->fetchAll();
        return $this->generateResponse($list);
    }

    /**
     * Create a new Entity
     *
     * @return Response
     */
    protected function handlePost() : Response
    {
        $data = $this->validateBody();
        $entity = $this->create($data);

        return $this->generateResponse($entity, 201);
    }

    /**
     * Update an Entity
     *
     * @param mixed $id
     * @return Response
     */
    protected function handleUpdate($id) : Response
    {
        $data = $this->validateBody();
        $entity = $this->update($id, $data);

        return $this->generateResponse($entity);
    }

    /**
     * Update a collection
     *
     * @return Response
     */
    protected function handleUpdateList() : Response
    {
        $data = $this->validateBody();
        $list = $this->updateList($data);

        return $this->generateResponse($list);
    }

    /**
     * Update some properties from an Entity
     *
     * @param mixed $id
     * @return Response
     */
    protected function handlePatch($id) : Response
    {
        $data = $this->validateBody();
        $entity = $this->patch($id, $data);

        return $this->generateResponse($entity);
    }

    /**
     * Updates some properties from a Collection
     *
     * @return Response
     */
    protected function handlePatchList() : Response
    {
        $data = $this->validateBody();
        $list = $this->patchList($data);

        return $this->generateResponse($list);
    }

    /**
     * Delete an Entity
     *
     * @param mixed $id
     * @return EmptyResponse
     */
    protected function handleDelete($id) : Response
    {
        $this->delete($id);
        return new EmptyResponse(204);
    }

    /**
     * Delete a Collection
     *
     * @return EmptyResponse
     */
    protected function handleDeleteList() : Response
    {
        $this->deleteList();
        return new EmptyResponse(204);
    }

    /**
     * @param $id
     * @return Entity
     */
    public function fetch($id) : EntityInterface
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    /**
     * @return Collection
     */
    public function fetchAll() : Collection
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    /**
     * @param array $data
     * @return Entity
     */
    public function create(array $data) : EntityInterface
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    /**
     * @param $id
     * @param array $data
     * @return Entity
     */
    public function update($id, array $data) : EntityInterface
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    /**
     * @param array $data
     * @return Collection
     */
    public function updateList(array $data) : Collection
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    /**
     * @param $id
     */
    public function delete($id)
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    public function deleteList()
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    public function head()
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    public function options()
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    /**
     * @param $id
     * @param array $data
     * @return Entity
     */
    public function patch($id, array $data) : EntityInterface
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }

    /**
     * @param array $data
     * @return Collection
     */
    public function patchList(array $data) : Collection
    {
        throw new MethodNotAllowedException('Method not allowed', 405);
    }
}
