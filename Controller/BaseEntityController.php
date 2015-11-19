<?php
/*
 * This file is a part of headlighthealth project.
 *
 * @author Alexandr Viniychuk <a@viniychuk.com>
 * created: 12:06 AM 6/19/15
 */

namespace Youshido\AdminBundle\Controller;


use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\DataTransformer\DateTimeToStringTransformer;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Youshido\AdminBundle\Service\AdminContext;

class BaseEntityController extends Controller
{

    public function defaultAction(Request $request, $module, $pageNumber = 1)
    {
        $this->get('adminContext')->setActiveModuleName($module);
        $moduleConfig = $this->get('adminContext')->getActiveModuleForAction('default');

        $cachedFilterForm = $this->get('session')->get($this->getFilterCacheKey($module), []);
        $filterForm = $this->createFilterForm($cachedFilterForm, $moduleConfig);
        $filterForm->handleRequest($request);
        $filterData = $filterForm->isSubmitted() && $filterForm->isValid() ? $filterForm->getData() : $cachedFilterForm;

        $this->get('session')->set($this->getFilterCacheKey($module), $filterData);

        /**
         * @var QueryBuilder $qb
         */
        $qb = $this->getDoctrine()->getManager()->createQueryBuilder();
        $qb->select('t')->from($moduleConfig['entity'], 't');

        if (!empty($filterData)) {
            foreach ($filterData as $key => $value) {
                if($value){
                    $qb->andWhere(sprintf('t.%s = :%s_query', $key, $key));
                    $qb->setParameter($key . '_query', $value);
                }
            }
        }

        list($orderField, $order) = $this->getSavedOrderFields($module);

        $order      = $request->get('order', $order ?: (!empty($moduleConfig['sort']) ? $moduleConfig['sort'][1] : 'asc'));
        $orderField = $request->get('orderField',  $orderField ?: (!empty($moduleConfig['sort']) ? $moduleConfig['sort'][0] : false));

        if ($orderField) {
            if (!empty($moduleConfig['subviews'][$orderField])) {
                if (!empty($moduleConfig['subviews'][$orderField]['sortable'])) {
                    if (!empty($moduleConfig['subviews'][$orderField]['sortable']['join'])) {
                        $join = $moduleConfig['subviews'][$orderField]['sortable']['join'];
                        $qb->leftJoin('t.' . $join[0], $join[1]);
                    }

                    $sort = $moduleConfig['subviews'][$orderField]['sortable']['sort'];
                    $qb->orderBy($sort[0], $order);
                }
            } else {
                if(!empty($moduleConfig['columns'][$orderField]['sortable'])){
                    if (!empty($moduleConfig['columns'][$orderField]['sortable']['join'])) {
                        $join = $moduleConfig['columns'][$orderField]['sortable']['join'];
                        $qb->leftJoin('t.' . $join[0], $join[1]);
                    }

                    $sort = $moduleConfig['columns'][$orderField]['sortable']['sort'];
                    $qb->orderBy($sort[0], $order);
                }else{
                    $qb->orderBy('t.' . $orderField, $order);
                }
            }

            $this->saveOrderFields($orderField, $order, $module);
        }

        if (!empty($moduleConfig['where'])) {
            $qb->where($moduleConfig['where']);
        }

        if(!empty($moduleConfig['actions']['default']['handler'])){
            $handlers = (array) $moduleConfig['actions']['default']['handler'];

            foreach($handlers as $handler){
                $qb = $this->get('adminContext')->prepareService($handler[0])->$handler[1]($qb);
            }
        }

        $perPageCount = isset($moduleConfig['limit']) ? $moduleConfig['limit'] : 20;
        $paginator    = $this->getPaginated($qb, $this->getPage($request, $module), $perPageCount);

        $ids = [];
        foreach ($paginator as $item) {
            $ids[] = $item->getId();
        }
        $this->saveLastIds($module, $ids);

        $template     = empty($moduleConfig['actions']['default']['template']) ? '@YAdmin/List/default.html.twig' : $moduleConfig['actions']['default']['template'];

        $additionalParameters = [];
        foreach($request->query->getIterator() as $key  => $value){
            $additionalParameters[$key] = $value;
        }

        return $this->render($template, [
            'objects'      => $paginator,
            'moduleConfig' => $moduleConfig,
            'tableTitle'   => isset($moduleConfig['actions']['default']['title']) ? $moduleConfig['actions']['default']['title'] : '',
            'action'       => 'default',
            'filters'      => $filterForm->createView(),
            'order'        => $order,
            'orderField'   => $orderField,
            'pager'        => [
                'currentPage' => $pageNumber,
                'route'       => $request->get('_route'),
                'parameters'  => array_merge($request->attributes->get('_route_params', []), $additionalParameters),
                'pagesCount'  => ceil(count($paginator) / $perPageCount),
            ],
        ]);
    }

    private function saveLastIds($module, $ids)
    {
        $this->get('session')->set('admin.last_ids' . $module, $ids);
    }

    protected function getLastIds($module)
    {
        return $this->get('session')->get('admin.last_ids' . $module, []);
    }

    private function createFilterForm($filterData, $moduleConfig)
    {
        /** @var FormBuilder $filtersBuilder */
        $filtersBuilder = $this->get('form.factory')
            ->createNamedBuilder('filter', 'form', $filterData, [
                'method' => 'get',
                'csrf_protection' => false,
                'attr' => ['class' => 'form form-inline']
            ]);

        if (!empty($moduleConfig['filters'])) {
            foreach ($moduleConfig['filters'] as $key => $value) {
                $options = $moduleConfig['columns'][$key];
                $options['required'] = false;
                $options['placeholder'] = $value['title'];
                $this->get('admin.form.helper')->buildFormItem($key, $options, $filtersBuilder);
            }
        }

        return $filtersBuilder->getForm();
    }

    public function duplicateAction($module, $id)
    {
        $this->get('adminContext')->setActiveModuleName($module);
        $moduleConfig = $this->get('adminContext')->getActiveModule();

        if ($id) {
            $object = $this->getDoctrine()->getRepository($moduleConfig['entity'])->find($id);
            $copy = clone $object;

            $em = $this->getDoctrine()->getManager();
            $em->persist($copy);
            $em->flush();
        }

        return $this->redirectToRoute($moduleConfig['actions']['default']['route'], array('module' => $module));
    }

    public function removeAction($module, Request $request, $id) {
        $this->get('adminContext')->setActiveModuleName($module);
        $moduleConfig = $this->get('adminContext')->getActiveModule();

        if ($ids = $request->get('id')) {
            $ids          = array($ids);
            $entities = $this->getDoctrine()->getRepository($moduleConfig['entity'])->findBy(array('id' => $ids));
            $em = $this->getDoctrine()->getManager();
            foreach($entities as $object) {
                $em->remove($object);
            }
            $em->flush();

            $this->addFlash('success', 'Element was removed!');
        }
        return $this->redirectToRoute($moduleConfig['actions']['default']['route'], ['module' => $module]);
    }

    protected function getPage(Request $request, $module)
    {
        $cacheKey = sprintf('admin_page_number_%s', $module);

        if($pageNumber = $request->get('pageNumber', false)) {
            $this->get('session')->set($cacheKey, $pageNumber);

            return $pageNumber;
        }else{
            if($this->get('session')->has($cacheKey)){
                return (int) $this->get('session')->get($cacheKey);
            }
        }

        return 1;
    }

    protected function exportAction($moduleConfig)
    {
        $this->get('adminContext')->setActiveModuleName($moduleConfig);
        $moduleConfig = $this->get('adminContext')->getActiveModuleForAction('export');

        $filename = $this->get('adminExcelExporter')->export($moduleConfig);

        $response = new Response();
        $response->headers->set('Cache-Control', 'private');
        $response->headers->set('Content-type', mime_content_type($filename));
        $response->headers->set('Content-Disposition',
            'attachment; filename="' . (new \DateTime())->format('Y.m.d H:i:s'). '.' . pathinfo($filename, PATHINFO_EXTENSION) . '";');
        $response->headers->set('Content-length', filesize($filename));

        $response->sendHeaders();

        $response->setContent(readfile($filename));

        return $response;
    }

    protected function processDetailAction($moduleConfig, $object = null, Request $request, $actionName)
    {
        $this->get('adminContext')->setActiveModuleName($moduleConfig);
        $moduleConfig = $this->get('adminContext')->getActiveModuleForAction($actionName);

        if (empty($moduleConfig['actions'][$actionName])) {
            return $this->redirectToRoute('admin.dashboard');
        }

        if (!$object) $object = $this->getOrCreateObjectFromRequest($request);

        $moduleConfig = $this->get('adminContext')->getActiveModuleForAction($actionName);

        if (!$this->get('admin.security')->isGranted($object, $moduleConfig, $actionName)) {
            throw new AccessDeniedException();
        }

        $vars = $object ? $this->callHandlersWithParams('load', [$object, $request]) : [];
        $form = $this->buildForm($object, $moduleConfig);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->callHandlersWithParams('validate', [$object, $request]);
            if ($form->isValid()) {
                $this->callHandlersWithParams('save', [$object, $request]);
                $this->saveValidObject($object);
                $this->addFlash('success', 'Your changes was has been saved');
                if (!empty($moduleConfig['handlers']['redirect'])) {
                    return $this->get(substr($moduleConfig['handlers']['redirect'][0], 1))->$moduleConfig['handlers']['redirect'][1]($object);
                } else {
                    return $this->redirectToRoute($moduleConfig['actions']['edit']['route'], ['module' => $moduleConfig['name'], 'id' => $object->getId()]);
                }
            }
        }

        $this->callHandlersWithParams('render', [$object, $request]);

        $vars = array_merge($vars, [
            'object'       => $object,
            'pageTitle'    => isset($moduleConfig['actions'][$actionName]['page_title']) ? $moduleConfig['actions'][$actionName]['page_title'] : '',
            'actionName'   => $actionName,
            'moduleConfig' => $this->get('adminContext')->getActiveModuleForAction($actionName),
            'form'         => $form->createView(),
        ]);

        return $this->render('@YAdmin/List/view.html.twig', $vars);
    }

    protected function callHandlersWithParams($eventName, $params)
    {
        $moduleConfig = $this->get('adminContext')->getActiveModuleForAction($eventName);
        $result       = array();
        if (!empty($moduleConfig['handlers'][$eventName])) {
            if (!is_array($moduleConfig['handlers'][$eventName])) {
                throw new \RuntimeException('Invalid configuration for handlers for ' . $eventName);
            }
            foreach ($moduleConfig['handlers'][$eventName] as $handler) {
                $service = $this->get(substr($handler[0], 1));
                if (empty($handler[1])) $handler[1] = Inflector::camelize(str_replace('.', ' ', $eventName)) . 'Handler';
                $res = call_user_func_array(array($service, $handler[1]), $params);
                if ($res) {
                    $result = array_merge($result, (array)$res);
                }
            }
        }
        return $result;
    }

    protected function getOrCreateObjectFromRequest(Request $request)
    {
        $moduleConfig = $this->get('adminContext')->getActiveModule();

        if ($id = $request->get('id')) {
            return $this->getDoctrine()->getRepository($moduleConfig['entity'])->find($id);
        } else {
            return new $moduleConfig['entity'];
        }
    }

    protected function saveValidObject($object)
    {
        $em = $this->getDoctrine()->getManager();
        $em->persist($object);
        $em->flush();
    }

    protected function buildForm($object, $config)
    {
        $formBuilder = $this->createFormBuilder($object, ['allow_extra_fields' => true, 'attr' => [
            'enctype'    => 'multipart/form-data',
            'novalidate' => 'novalidate'
        ]]);

        foreach ($config['columns'] as $column => $info) {
            if(!array_key_exists('entity', $info)){
                $info['entity'] = $config['entity'];
            }


            $this->get('admin.form.helper')->buildFormItem($column, $info, $formBuilder, $object);
        }
        $this->callHandlersWithParams('form.build', [$object, $formBuilder]);
        return $formBuilder->getForm();
    }

    protected function getPaginated($query, $pageNumber, $count = 20)
    {
        $query->setFirstResult(($pageNumber - 1) * $count)
            ->setMaxResults($count);

        return new Paginator($query);
    }

    protected function getFilterCacheKey($module)
    {
        return sprintf('filers_%s', $module);
    }

    private function saveOrderFields($orderField, $order, $module)
    {
        $this->get('session')->set(sprintf('%s_orders', $module), [
            'order'      => $order,
            'orderField' => $orderField
        ]);
    }

    private function getSavedOrderFields($module)
    {
        $saved = $this->get('session')->get(sprintf('%s_orders', $module), []);

        return [
            isset($saved['orderField']) ? $saved['orderField'] : null,
            isset($saved['order']) ? $saved['order'] : null
        ];
    }
}
