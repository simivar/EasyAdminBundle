<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Controller;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Builder\ActionBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Configuration\AssetConfig;
use EasyCorp\Bundle\EasyAdminBundle\Configuration\CrudConfig;
use EasyCorp\Bundle\EasyAdminBundle\Configuration\DetailPageConfig;
use EasyCorp\Bundle\EasyAdminBundle\Configuration\FormPageConfig;
use EasyCorp\Bundle\EasyAdminBundle\Configuration\IndexPageConfig;
use EasyCorp\Bundle\EasyAdminBundle\Context\ApplicationContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\ApplicationContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Controller\CrudControllerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterCrudActionEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityUpdatedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Factory\EntityFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FormFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\PaginatorFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\PropertyFactory;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\EasyAdminBatchFormType;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\FiltersFormType;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
abstract class AbstractCrudController extends AbstractController implements CrudControllerInterface
{
    abstract public function configureCrud(CrudConfig $crudConfig): CrudConfig;

    public function configureAssets(AssetConfig $assetConfig): AssetConfig
    {
        return $assetConfig;
    }

    /**
     * {@inheritdoc}
     */
    abstract public function configureProperties(string $action): iterable;

    public function configureIndexPage(IndexPageConfig $indexPageConfig): IndexPageConfig
    {
        return $indexPageConfig;
    }

    public function configureDetailPage(DetailPageConfig $detailPageConfig): DetailPageConfig
    {
        return $detailPageConfig;
    }

    public function configureFormPage(FormPageConfig $formPageConfig): FormPageConfig
    {
        return $formPageConfig;
    }

    public static function getSubscribedServices()
    {
        return array_merge(parent::getSubscribedServices(), [
            'event_dispatcher' => '?'.EventDispatcherInterface::class,
            'ea.action_builder' => '?'.ActionBuilder::class,
            'ea.context_provider' => '?'.ApplicationContextProvider::class,
            'ea.entity_factory' => '?'.EntityFactory::class,
            'ea.entity_repository' => '?'.EntityRepositoryInterface::class,
            'ea.form_factory' => '?'.FormFactory::class,
            'ea.paginator_factory' => '?'.PaginatorFactory::class,
        ]);
    }

    public function index(): Response
    {
        $event = new BeforeCrudActionEvent($this->getContext());
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        $entityDto = $this->get('ea.entity_factory')->create();
        $queryBuilder = $this->createIndexQueryBuilder($this->getContext()->getSearch(), $entityDto);
        $paginator = $this->get('ea.paginator_factory')->create($queryBuilder);

        $entityInstances = $paginator->getResults();
        $configuredProperties = iterator_to_array($this->configureProperties('index'));
        $entities = $this->get('ea.entity_factory')->createAll($entityDto, $entityInstances, $configuredProperties);

        $parameters = [
            'entities' => $entities,
            'paginator' => $paginator,
            'batch_form' => $this->createBatchForm($entityDto->getFqcn())->createView(),
            'delete_form_template' => $this->get('ea.form_factory')->createDeleteForm(['entityId' => '__id__'])->createView(),
        ];

        $event = new AfterCrudActionEvent($this->getContext(), $parameters);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        return $this->render(
            $this->getContext()->getTemplatePath('crud/index'),
            $this->getTemplateParameters('index', $event->getTemplateParameters())
        );
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto): QueryBuilder
    {
        return $this->get('ea.entity_repository')->createQueryBuilder($searchDto, $entityDto);
    }

    public function showFilters(): Response
    {
        $filtersForm = $this->get('form.factory')->createNamed('filters', FiltersFormType::class, null, [
            'method' => 'GET',
            'action' => $this->getContext()->getRequest()->query->get('referrer'),
        ]);
        $filtersForm->handleRequest($this->getContext()->getRequest());

        $templateParameters = [
            'filters_form' => $filtersForm->createView(),
        ];

        return $this->render(
            $this->getContext()->getTemplatePath('crud/filters'),
            $this->getTemplateParameters('showFilters', $templateParameters)
        );
    }

    public function detail(): Response
    {
        $event = new BeforeCrudActionEvent($this->getContext());
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        $configuredProperties = $this->configureProperties('detail');
        $entityDto = $this->get('ea.entity_factory')->create($configuredProperties);

        $actions = $this->get('ea.action_builder')->setItems($this->getContext()->getCrud()->getPage()->getActions())->build();

        $parameters = [
            'actions' => $actions,
            'entity' => $entityDto,
            'delete_form' => $this->get('ea.form_factory')->createDeleteForm()->createView(),
        ];

        $event = new AfterCrudActionEvent($this->getContext(), $parameters);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        return $this->render(
            $this->getContext()->getTemplatePath('crud/detail'),
            $this->getTemplateParameters('detail', $event->getTemplateParameters())
        );
    }

    public function edit(): Response
    {
        $event = new BeforeCrudActionEvent($this->getContext());
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        $configuredProperties = $this->configureProperties('edit');
        $entityDto = $this->get('ea.entity_factory')->create($configuredProperties);
        $entityInstance = $entityDto->getInstance();

        /*
        if ($this->request->isXmlHttpRequest() && $property = $this->request->query->get('property')) {
            $newValue = 'true' === mb_strtolower($this->request->query->get('newValue'));
            $fieldsMetadata = $this->entity['list']['fields'];

            if (!isset($fieldsMetadata[$property]) || 'toggle' !== $fieldsMetadata[$property]['dataType']) {
                throw new \RuntimeException(sprintf('The type of the "%s" property is not "toggle".', $property));
            }

            $this->updateEntityProperty($entity, $property, $newValue);

            // cast to integer instead of string to avoid sending empty responses for 'false'
            return new Response((int) $newValue);
        }
        */

        $editForm = $this->createEditForm($entityDto);
        $editForm->handleRequest($this->getContext()->getRequest());
        if ($editForm->isSubmitted() && $editForm->isValid()) {
            // TODO:
            // $this->processUploadedFiles($editForm);

            $event = new BeforeEntityUpdatedEvent($entityInstance);
            $this->get('event_dispatcher')->dispatch($event);
            $entityInstance = $event->getEntityInstance();

            $this->updateEntity($this->get('doctrine')->getManagerForClass($entityDto->getFqcn()), $entityInstance);

            $this->get('event_dispatcher')->dispatch(new AfterEntityUpdatedEvent($entityInstance));

            return $this->redirectToReferrer();
        }

        $parameters = [
            'action' => 'edit',
            'edit_form' => $editForm->createView(),
            'entity' => $entityDto->with(['instance' => $entityInstance]),
            'delete_form' => $this->get('ea.form_factory')->createDeleteForm()->createView(),
        ];

        $event = new AfterCrudActionEvent($this->getContext(), $parameters);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        return $this->render(
            $this->getContext()->getTemplatePath('crud/edit'),
            $this->getTemplateParameters('edit', $event->getTemplateParameters())
        );
    }

    protected function updateEntity(ManagerRegistry $entityManager, $entityInstance)
    {
        // there's no need to persist the entity explicitly again because it's already
        // managed by Doctrine. The instance is passed to the method in case the
        // user application needs to make decisions
        $entityManager->flush();
    }

    protected function createEditForm(EntityDto $entityDto): FormInterface
    {
        return $this->get('ea.form_factory')->createEditForm($entityDto);
    }

    /**
     * @return RedirectResponse
     */
    protected function redirectToReferrer()
    {
        // TODO: fix this
        return new RedirectResponse($this->getContext()->getDashboardRouteName());

        $refererUrl = $this->request->query->get('referrer', '');
        $refererAction = $this->request->query->get('action');

        // 1. redirect to list if possible
        if ($this->isActionAllowed('list')) {
            if (!empty($refererUrl)) {
                return $this->redirect(urldecode($refererUrl));
            }

            return $this->redirectToRoute('easyadmin', [
                'action' => 'list',
                'entity' => $this->entity['name'],
                'menuIndex' => $this->request->query->get('menuIndex'),
                'submenuIndex' => $this->request->query->get('submenuIndex'),
            ]);
        }

        // 2. from new|edit action, redirect to edit if possible
        if (\in_array($refererAction, ['new', 'edit']) && $this->isActionAllowed('edit')) {
            return $this->redirectToRoute('easyadmin', [
                'action' => 'edit',
                'entity' => $this->entity['name'],
                'menuIndex' => $this->request->query->get('menuIndex'),
                'submenuIndex' => $this->request->query->get('submenuIndex'),
                'id' => ('new' === $refererAction)
                    ? PropertyAccess::createPropertyAccessor()->getValue($this->request->attributes->get('easyadmin')['item'], $this->entity['primary_key_field_name'])
                    : $this->request->query->get('id'),
            ]);
        }

        // 3. from new action, redirect to new if possible
        if ('new' === $refererAction && $this->isActionAllowed('new')) {
            return $this->redirectToRoute('easyadmin', [
                'action' => 'new',
                'entity' => $this->entity['name'],
                'menuIndex' => $this->request->query->get('menuIndex'),
                'submenuIndex' => $this->request->query->get('submenuIndex'),
            ]);
        }

        return new RedirectResponse($this->getContext()->getDashboardRouteName());
    }

    /**
     * Used to add/modify/remove parameters before passing them to the Twig template.
     * $templateName = 'index', 'detail' or 'form'.
     */
    public function getTemplateParameters(string $actionName, array $parameters): array
    {
        return $parameters;
    }

    protected function getContext(): ?ApplicationContext
    {
        return $this->get('ea.context_provider')->getContext();
    }

    protected function createBatchForm(string $entityName): FormInterface
    {
        return $this->get('form.factory')->create();

        return $this->get('form.factory')->createNamed('batch_form', EasyAdminBatchFormType::class, null, [
            'action' => $this->generateUrl('easyadmin', ['action' => 'batch', 'entity' => $entityName]),
            'entity' => $entityName,
        ]);
    }
}