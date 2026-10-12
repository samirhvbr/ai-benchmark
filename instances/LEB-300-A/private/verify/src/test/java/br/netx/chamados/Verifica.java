package br.netx.chamados;

import org.junit.jupiter.api.extension.ExtendWith;

import java.lang.annotation.ElementType;
import java.lang.annotation.Retention;
import java.lang.annotation.RetentionPolicy;
import java.lang.annotation.Target;

/** Os veredictos que este método emite. Se ele falhar por culpa do sistema avaliado, esses veredictos viram "não corrigida" em vez de sumir. */
@Retention(RetentionPolicy.RUNTIME)
@Target({ElementType.METHOD, ElementType.TYPE})
@ExtendWith(VerificaExtension.class)
@interface Verifica {
    String[] value() default {};
}
