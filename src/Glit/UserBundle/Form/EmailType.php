<?php
namespace Glit\UserBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Validator\Constraints as Constraints;

class EmailType extends AbstractType {

    public function buildForm(FormBuilder $builder, array $options) {
        $builder->add('address', 'email');
    }

    public function getDefaultOptions(array $options) {
        return array(
            'data_class' => 'Glit\UserBundle\Entity\Email',
        );
    }

    public function getName() {
        return 'user_email';
    }
}