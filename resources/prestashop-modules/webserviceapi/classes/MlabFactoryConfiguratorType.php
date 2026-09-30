<?php

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Callback;

/** A compound form at product root level is rendered as a tab in PrestaShop 8.1+. */
class MlabFactoryConfiguratorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('configurator_active', CheckboxType::class, array(
            'label' => 'Configuratore attivo', 'required' => false,
        ));
        $builder->add('configurator_json', TextareaType::class, array(
            'label' => 'Configuratore JSON', 'required' => false, 'empty_data' => '',
            'attr' => array('rows' => 12, 'spellcheck' => 'false'),
            'constraints' => array(new Callback(function ($value, $context) {
                if ($value === null || trim($value) === '') {
                    return;
                }
                json_decode($value);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $context->buildViolation('JSON non valido: ' . json_last_error_msg())->addViolation();
                }
            })),
        ));
    }
}
