<?php

namespace Youshido\AdminBundle\Controller;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\DataTransformer\DateTimeToStringTransformer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Youshido\CMSBundle\Structure\Attribute\AttributedTrait;

class DictionaryController extends BaseEntityController
{

    /**
     * @Route("/dictionary/{module}/{pageNumber}", name="admin.dictionary.default", requirements={ "pageNumber" : "\d+"})
     */
    public function defaultAction(Request $request, $module, $pageNumber = 1)
    {
        return parent::defaultAction($request, $module, $pageNumber);
    }

    /**
     * @Route("/dictionary/{module}/add", name="admin.dictionary.add")
     */
    public function addAction($module, Request $request)
    {
        return $this->processDetailAction($module, null, $request, 'add');
    }

    /**
     * @Route("/dictionary/{module}/export", name="admin.dictionary.export")
     */
    public function exportAction($module, Request $request)
    {
        return parent::exportAction($module, $request);
    }

    /**
     * @Route("/dictionary/{module}/edit/{id}", name="admin.dictionary.edit")
     */
    public function editAction($module, $id, Request $request)
    {
        return $this->processDetailAction($module, null, $request, 'edit');
    }

    /**
     * @Route("/dictionary/{module}/delete-attribute-file/{id}", name="admin.deleteAttributeFile")
     */
    public function deleteAttributeFile($module, $id, Request $request)
    {
        $this->get('adminContext')->setActiveModuleName($module);
        $moduleConfig = $this->get('adminContext')->getActiveModule();
        $em = $this->getDoctrine()->getManager();

        $object = $this->getDoctrine()->getRepository($moduleConfig['entity'])->find($id);
        $path = $request->get('path');

        if ($object && $path) {
            $propertyAccessor = PropertyAccess::createPropertyAccessor();
            $objectMetadata = $em->getClassMetadata(get_class($object));
            $needRefresh = false;

            foreach ($objectMetadata->getFieldNames() as $fieldName) {
                $fieldValue = $propertyAccessor->getValue($object, $fieldName);

                if (!is_string($fieldValue)) {
                    // File paths are always strings. So non string value can be skipped.
                    continue;
                }

                // FIXME: `strcmp()` should be used. But there's a bug now that
                //        $path contains one extra leading slash. Cannot say
                //        how it got there so I'm keeping the condition now.
                if (strpos($path, $fieldValue) !== false) {
                    $basePath = $this->get('kernel')->getRootDir() . '/../web';
                    if (is_file($basePath . $path)) {
                        unlink($basePath . $path);
                    }
                    $propertyAccessor->setValue($object, $fieldName, null);
                    $needRefresh = true;
                }
            }

            if ($needRefresh) {
                $em->flush();
            }
        }

        return $this->redirectToRoute($moduleConfig['actions']['edit']['route'], ['module' => $moduleConfig['name'], 'id' => $id]);
    }

    /**
     * @Route("/dictionary/{module}/duplicate/{id}", name="admin.dictionary.duplicate")
     */
    public function duplicateAction($module, $id, Request $request)
    {
        return parent::duplicateAction($module, $id, $request);
    }

    /**
     * @Route("/dictionary/{module}/remove", name="admin.dictionary.remove")
     */
    public function removeAction($module, Request $request)
    {
        return parent::removeAction($module, $request);
    }

    /**
     * @param Request $request
     *
     * @Route("/dictionary/{module}/batch/remove", name="dictionary.batchs.remove")
     */
    public function batchRemoveAction(Request $request, $module)
    {
        if($request->getMethod() == 'POST' && ($ids = $request->get('ids', []))){

            $this->get('adminContext')->setActiveModuleName($module);
            $moduleConfig = $this->get('adminContext')->getActiveModule();

            $query = $this->getDoctrine()->getRepository($moduleConfig['entity'])
                ->createQueryBuilder('t');

            $query
                ->delete()
                ->where($query->expr()->in('t.id', $ids))
                ->getQuery()
                ->execute();

            $this->addFlash('success', 'Entities deleted');
        }

        $referer = $request->headers->get('referer', false);
        if($referer){
            return $this->redirect($referer = $request->headers->get('referer'));
        }

        return $this->redirectToRoute('admin.dashboard');
    }
}
