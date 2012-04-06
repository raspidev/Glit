<?php
namespace Glit\ProjectsBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Glit\GitoliteBundle\Git\Repository;
use Glit\GitoliteBundle\Git\Commit;
use Glit\CoreBundle\Utils\StringObject;

class DefaultController extends Controller {

    /**
     * @Route("/projects/")
     * @Template()
     */
    public function indexAction() {
        return array();
    }

    /**
     * @Route("{accountName}/{projectPath}")
     * @Template()
     */
    public function viewAction($accountName, $projectPath) {
        /** @var $account \Glit\CoreBundle\Entity\Account */
        $account = $this->getDoctrine()->getRepository('GlitCoreBundle:Account')->findOneByUniqueName($accountName);

        if (null == $account) {
            throw $this->createNotFoundException(sprintf('Account %s not found', $accountName));
        }

        /** @var $project \Glit\ProjectsBundle\Entity\Project */
        $project = $this->getDoctrine()->getRepository('GlitProjectsBundle:Project')->findOneBy(array('path' => $projectPath,
                                                                                                     'owner' => $account->getId()));

        if (null == $project) {
            throw $this->createNotFoundException(sprintf('Project %s not found', $projectPath));
        }

        // Load data from repository
        $repository = $this->getGitoliteAdmin()->getRepository($project->getFullPath() . '.git');

        if ($repository->isNew()) {
            // TODO : check right for organization
            // Redirect to notFound has no write role on this project
            if ($account->getUniqueName() == $this->getCurrentUser()->getUniqueName()) {
                return $this->render('GlitProjectsBundle:Default:view-empty.html.twig', array('project' => $project,
                                                                                             'ssh'      => 'git@dev.glit.fr:' . $project->getFullPath() . '.git'));
            }
            else {
                throw $this->createNotFoundException(sprintf('Project %s not found', $projectPath));
            }
        }

        $branch = $project->getDefaultBranch();

        /** @var $commit \Glit\GitoliteBundle\Git\Commit */
        $commit      = $repository->getBranch($branch)->getTip();
        $commit_user = array(
            'name' => $commit->getAuthor()->name
        );

        // Find if user whose commit is in glit by email adresse
        /** @var $glitUser \Glit\UserBundle\Entity\User */
        $glitUser = $this->getDoctrine()->getRepository('GlitUserBundle:User')->findOneByEmail($commit->getAuthor()->email);
        if (!is_null($glitUser)) {
            $commit_user['name'] = $glitUser->getUniqueName();
            $commit_user['url']  = $this->generateUrl('glit_core_account_view', array('uniqueName' => $glitUser->getUniqueName()));
        }

        $readme = null;

        $tree = array();
        foreach ($commit->getTree()->getNodes() as $treeNode) {
            /** @var $treeNode \Glit\GitoliteBundle\Git\TreeNode */
            if (StringObject::staticStartsWith($treeNode->getName(), 'README.', false) || strtoupper($treeNode->getName()) == 'README') {
                $readme = $treeNode;
            }

            /** @var $lastCommit Commit */
            $lastCommit = $treeNode->getLastCommit($commit);

            $tree[] = array(
                'name'                 => $treeNode->getName(),
                'is_dir'               => $treeNode->getIsDir(),
                'last_updated'         => $lastCommit->getDate(),
                'last_updated_summary' => $lastCommit->getSummary()
            );
        }

        $readme->getHistory($commit);
        //var_dump($commit->diffWithPreviousCommit());

        return array('project'     => $project,
                     'commit_user' => $commit_user,
                     'branch'      => $branch,
                     'commit'      => $commit,
                     'tree'        => $tree,
                     'readme'      => $readme);
    }

    /**
     * @Route("{uniqueName}/projects/new")
     * @Template()
     */
    public
    function newAction($uniqueName) {
        $account = $this->getDoctrine()->getRepository('GlitCoreBundle:Account')->findOneByUniqueName($uniqueName);
        $scope   = $uniqueName == $this->getCurrentUser()->getUniqueName() ? 'user' : 'organization';

        // Check if current user can create project for this account
        if ($account != null && $scope != 'user') {
            // user create project for another.
            // scope : organization

            // TODO : Check rights
        }

        if (null === $account) {
            throw $this->createNotFoundException('Account not found to create project.');
        }

        $project = new \Glit\ProjectsBundle\Entity\Project($account);
        $form    = $this->createForm(new \Glit\ProjectsBundle\Form\ProjectType(), $project);

        if ('POST' == $this->getRequest()->getMethod()) {
            $form->bindRequest($this->getRequest());
            if ($form->isValid()) {
                $this->getDefaultEntityManager()->persist($project);

                // Create repository
                $keys = array();

                switch ($scope) {
                    case 'user':
                        foreach ($this->getCurrentUser()->getSshKeys() as $k) {
                            /** @var $k \Glit\UserBundle\Entity\SshKey */
                            $keys[] = $k->getKeyIdentifier();
                        }
                        break;
                }

                $this->getGitoliteAdmin()->createRepository($project->getFullPath(), $keys);

                $this->getDefaultEntityManager()->flush();

                $this->setFlash('success', sprintf('Projet %s successfully created !', $project->getName()));
                return $this->redirect($this->generateUrl('_welcome'));
            }
        }

        return array('uniqueName' => $uniqueName,
                     'form'       => $form->createView());
    }

    /**
     * @return \Glit\UserBundle\Entity\User
     */
    protected
    function getCurrentUser() {
        return $this->get('security.context')->getToken()->getUser();
    }

    /**
     * Set session flash
     * @param $action
     * @param $value
     */
    protected
    function setFlash($action, $value) {
        $this->container->get('session')->setFlash($action, $value);
    }

    /**
     * @return \Doctrine\ORM\EntityManager
     */
    protected
    function getDefaultEntityManager() {
        return $this->getDoctrine()->getEntityManager();
    }

    /**
     * @return \Glit\GitoliteBundle\Admin\Gitolite
     */
    protected
    function getGitoliteAdmin() {
        return $this->get('glit_gitolite.admin');
    }
}
