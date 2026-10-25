package com.condodesk;

import org.junit.jupiter.api.extension.ExtendWith;

import java.lang.annotation.ElementType;
import java.lang.annotation.Retention;
import java.lang.annotation.RetentionPolicy;
import java.lang.annotation.Target;

/** The verdicts this method writes. If it fails because of the system under evaluation, they become "not fixed" instead of vanishing. */
@Retention(RetentionPolicy.RUNTIME)
@Target({ElementType.METHOD, ElementType.TYPE})
@ExtendWith(VerifiesExtension.class)
@interface Verifies {
    String[] value() default {};
}
