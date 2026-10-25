package com.condodesk.billing;

import com.condodesk.domain.AppException;
import org.w3c.dom.Document;
import org.w3c.dom.Element;
import org.w3c.dom.NodeList;
import org.xml.sax.ErrorHandler;
import org.xml.sax.SAXException;
import org.xml.sax.SAXParseException;

import javax.xml.XMLConstants;
import javax.xml.parsers.DocumentBuilder;
import javax.xml.parsers.DocumentBuilderFactory;
import javax.xml.parsers.ParserConfigurationException;
import java.io.ByteArrayInputStream;
import java.io.IOException;
import java.math.BigDecimal;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.List;

/** Reads the transactions of a bank statement in OFX 2 (XML). */
public final class OfxParser {

    /** One statement line. {@code amount} is positive for a credit. */
    public record Entry(String type, LocalDate posted, BigDecimal amount, String fitId, String memo) {}

    private static final ErrorHandler SILENT = new ErrorHandler() {
        @Override public void warning(SAXParseException e) { }
        @Override public void error(SAXParseException e) throws SAXException { throw e; }
        @Override public void fatalError(SAXParseException e) throws SAXException { throw e; }
    };

    private OfxParser() {
    }

    public static List<Entry> parse(byte[] xml) {
        try {
            DocumentBuilder builder = factory().newDocumentBuilder();
            builder.setErrorHandler(SILENT);
            Document doc = builder.parse(new ByteArrayInputStream(xml));
            NodeList list = doc.getElementsByTagName("STMTTRN");
            List<Entry> entries = new ArrayList<>();
            for (int i = 0; i < list.getLength(); i++) {
                Element t = (Element) list.item(i);
                String posted = text(t, "DTPOSTED");
                if (posted.length() < 8) throw invalid();
                entries.add(new Entry(text(t, "TRNTYPE"), LocalDate.parse(posted.substring(0, 8), DateTimeFormatter.BASIC_ISO_DATE),
                        new BigDecimal(text(t, "TRNAMT")), text(t, "FITID"), text(t, "MEMO")));
            }
            return entries;
        } catch (SAXException | IOException | NumberFormatException | DateTimeParseException e) {
            throw invalid();
        } catch (ParserConfigurationException e) {
            throw new IllegalStateException(e);
        }
    }

    private static DocumentBuilderFactory factory() throws ParserConfigurationException {
        DocumentBuilderFactory f = DocumentBuilderFactory.newInstance();
        return f;
    }

    private static String text(Element parent, String tag) {
        NodeList n = parent.getElementsByTagName(tag);
        return n.getLength() == 0 ? "" : n.item(0).getTextContent().trim();
    }

    private static AppException invalid() {
        return AppException.invalid("The statement is not a valid OFX file.");
    }
}
