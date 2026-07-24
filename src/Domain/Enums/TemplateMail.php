<?php

namespace PS\Webservice\Domain\Enums;

enum TemplateMail: string
{
    case SIGNUP = 'account';
    case RESET_PASSWORD = 'password_query';
    case PASSWORD_UPDATED = 'password';
}