# -*- coding: utf-8 -*-
from . import models
from . import controllers

from odoo.addons.payment import reset_payment_provider, setup_provider


def post_init_hook(env):
    # Odoo 20 removed payment.provider.state; default payment methods are
    # activated by core's setup_provider() (the payment_demo/payment_stripe
    # pattern). Without this hook the BMaGlass method stays inactive
    # (active = NULL) and never appears at checkout - install-test bug B11.
    setup_provider(env, "bmaglass")


def uninstall_hook(env):
    reset_payment_provider(env, "bmaglass")
