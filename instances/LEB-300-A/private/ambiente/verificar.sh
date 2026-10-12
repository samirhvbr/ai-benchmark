#!/usr/bin/env bash
exec "${LEB_INSTANCIA_DIR:?}/../../operador/avaliar.sh" verificar "${LEB_ENTREGA_DIR:?}"
