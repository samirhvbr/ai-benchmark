#!/usr/bin/env bash
# Called by the harness with cwd = private/. LEB_ENTREGA_DIR is the code under test; LEB_INSTANCIA_DIR is the instance root.
exec "${LEB_INSTANCIA_DIR:?}/../../operador/avaliar.sh" caracterizar "${LEB_ENTREGA_DIR:?}"
